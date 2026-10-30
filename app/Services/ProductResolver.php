<?php

namespace App\Services;

use App\Models\CompetitionTransaction;
use App\Models\CompetitionWallet;
use App\Models\GameTransaction;
use App\Models\GameWallet;
use App\Models\LedgerEntry;
use App\Models\Product;
use App\Models\Wager;
use App\Models\WagerSettlement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Which product a ledger entry belongs to. First match wins:
 *  1. a reversal takes the product of the entry it reverses (metadata original_entry_id);
 *  2. a wager, or a wager settlement through its wager, takes that wager's product;
 *  3. a kadi game wallet or game transaction, or metadata game_wallet_id → kadi;
 *  4. a competition wallet or competition transaction, or metadata competition_wallet_id → the
 *     competition's game_type (1 tournament, 2 jackpot; anything else is logged and read as tournament);
 *  5. otherwise null: a platform entry (deposit, withdrawal, excise, promo, referral, shop, adjustment).
 */
class ProductResolver
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function forPosting(?Model $reference, Model $wallet, array $metadata): ?int
    {
        if (isset($metadata['original_entry_id'])) {
            return LedgerEntry::where('entry_id', $metadata['original_entry_id'])->value('product_id');
        }

        $wagerProductId = match (true) {
            $reference instanceof Wager => $reference->product_id,
            $reference instanceof WagerSettlement => Wager::whereKey($reference->wager_id)->value('product_id'),
            $wallet instanceof Wager => $wallet->product_id,
            default => null,
        };

        if ($wagerProductId !== null) {
            return (int) $wagerProductId;
        }

        if ($wallet instanceof GameWallet || $reference instanceof GameWallet || $reference instanceof GameTransaction || isset($metadata['game_wallet_id'])) {
            return Product::idFor(Product::CODE_KADI);
        }

        $gameType = match (true) {
            $wallet instanceof CompetitionWallet => $wallet->game_type,
            $reference instanceof CompetitionWallet => $reference->game_type,
            $reference instanceof CompetitionTransaction => $this->competitionGameType($reference->competition_wallet_id),
            isset($metadata['competition_wallet_id']) => $this->competitionGameType($metadata['competition_wallet_id']),
            default => false,
        };

        return $gameType === false ? null : $this->competitionProductId($gameType);
    }

    /**
     * The same rules for an entry already in the ledger, read from its stored columns (for the backfill).
     */
    public function forStoredEntry(LedgerEntry $entry): ?int
    {
        $metadata = $entry->metadata ?? [];

        if (isset($metadata['original_entry_id'])) {
            $original = LedgerEntry::where('entry_id', $metadata['original_entry_id'])->first();

            return $original ? ($original->product_id ?? $this->forStoredEntry($original)) : null;
        }

        if ($entry->wallet_type === LedgerEntry::WALLET_TYPE_GAME
            || in_array($entry->referenceable_type, [GameWallet::class, GameTransaction::class], true)
            || isset($metadata['game_wallet_id'])) {
            return Product::idFor(Product::CODE_KADI);
        }

        if ($this->isBareHouseCut($entry, $metadata)) {
            return $this->forBareHouseCut($entry, $metadata['source'] ?? null);
        }

        $competitionWalletId = match (true) {
            $entry->wallet_type === LedgerEntry::WALLET_TYPE_COMPETITION => $entry->wallet_id,
            $entry->referenceable_type === CompetitionWallet::class => $entry->referenceable_id,
            $entry->referenceable_type === CompetitionTransaction::class => CompetitionTransaction::whereKey($entry->referenceable_id)->value('competition_wallet_id'),
            isset($metadata['competition_wallet_id']) => $metadata['competition_wallet_id'],
            default => false,
        };

        return $competitionWalletId === false ? null : $this->competitionProductId($this->competitionGameType($competitionWalletId));
    }

    /**
     * House cuts written from 2026-09-02 to 2026-09-20 carry only metadata.source: no reference and no
     * wallet context. Every house cut is game revenue, so the backfill must place each one.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function isBareHouseCut(LedgerEntry $entry, array $metadata): bool
    {
        return $entry->entry_type === 'house_cut'
            && $entry->referenceable_type === null
            && ! isset($metadata['game_wallet_id'])
            && ! isset($metadata['competition_wallet_id']);
    }

    /**
     * Kadi game sources are kadi. A competition_bet cut was written straight after the competition_bet
     * entries of the same stake (same second, metadata.house_cut equal to the cut), whose competition
     * transaction says which competition it was.
     */
    private function forBareHouseCut(LedgerEntry $entry, ?string $source): ?int
    {
        if (in_array($source, ['game_withdrawal', 'game_drop_payout', 'game_credit'], true)) {
            return Product::idFor(Product::CODE_KADI);
        }

        if ($source !== 'competition_bet') {
            return null;
        }

        $bet = LedgerEntry::where('entry_type', 'competition_bet')
            ->where('referenceable_type', CompetitionTransaction::class)
            ->where('id', '<', $entry->id)
            ->where('created_at', $entry->created_at)
            ->orderByDesc('id')
            ->limit(4)
            ->get()
            ->first(fn (LedgerEntry $candidate) => round((float) ($candidate->metadata['house_cut'] ?? -1), 2) === round((float) $entry->credit, 2));

        return $bet ? $this->forStoredEntry($bet) : null;
    }

    private function competitionGameType(mixed $competitionWalletId): ?int
    {
        $gameType = $competitionWalletId === null ? null : CompetitionWallet::whereKey($competitionWalletId)->value('game_type');

        return $gameType === null ? null : (int) $gameType;
    }

    private function competitionProductId(mixed $gameType): ?int
    {
        return match ((int) $gameType) {
            1 => Product::idFor(Product::CODE_KADI_TOURNAMENT),
            2 => Product::idFor(Product::CODE_KADI_JACKPOT),
            default => $this->unknownCompetitionType($gameType),
        };
    }

    private function unknownCompetitionType(mixed $gameType): ?int
    {
        Log::warning('Ledger entry for a competition of unknown game_type, tagged as a tournament', ['game_type' => $gameType]);

        return Product::idFor(Product::CODE_KADI_TOURNAMENT);
    }
}
