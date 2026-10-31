<?php

namespace App\Services;

use App\Models\ApiKey;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Wager;
use App\Models\WagerRound;
use App\Models\WagerSettlement;
use App\Models\Wallet;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Wagers on house-banked products. Idempotency is by natural key: the unique (product_id, reference) plus a
 * payload comparison, never the idempotency middleware. Lock order (section 3): round (shared for place), then the
 * wager row, then the player wallet, then the house wallet. Every balance is changed on the instance a locking read
 * returned, never on a plain re-read (which under REPEATABLE READ can return an older snapshot).
 */
class WagerService
{
    public function __construct(private LedgerService $ledger, private PlayerGate $gate, private WinningsTaxService $winningsTax) {}

    /**
     * Place a bet for a player.
     *
     * @param  array<string, mixed>  $data  product, reference, funding, stake|free_bet_amount, round_reference?, channel?, metadata?
     */
    public function place(ApiKey $key, Customer $player, array $data): WagerResult
    {
        $product = $this->allowedProduct($key, $data['product']);

        if (! $product) {
            return $this->productNotAllowed();
        }

        // A retry of a place that already went through is never refused for what changed since: it replays
        // (or conflicts) before the product, player and round checks. Plain read, no lock: it moves no money.
        $existing = $this->findByReference($product, $data['reference']);

        if ($existing) {
            return $this->replayOrConflict($existing, $player, $data);
        }

        if ($product->status !== Product::STATUS_ACTIVE) {
            return WagerResult::refused('product_not_active', 'This product is not taking bets', 403);
        }

        $allowed = $this->gate->canPlay($player);

        if (! $allowed->allowed) {
            return WagerResult::refused($allowed->code, $allowed->message, $allowed->statusCode, $allowed->extra);
        }

        try {
            return DB::transaction(fn () => $this->placeLocked($key, $player, $product, $data), 3);
        } catch (UniqueConstraintViolationException) {
            // A racing request inserted the round or the wager first. Never SELECT ... FOR UPDATE a row that
            // may not exist (an InnoDB gap lock deadlocks concurrent inserts): the unique key decides the race.
            $existing = $this->findByReference($product, $data['reference']);

            if ($existing) {
                return $this->replayOrConflict($existing, $player, $data);
            }

            return DB::transaction(fn () => $this->placeLocked($key, $player, $product, $data), 3);
        }
    }

    /**
     * The product, when the key may post wagers on it: it belongs to the key's game and is house-banked. A rake
     * product (kadi's) never takes wagers, even from its own game's key; a key without a game reaches none.
     */
    public function allowedProduct(ApiKey $key, string $code): ?Product
    {
        $product = Product::where('code', $code)->first();

        if (! $product || $key->game_id === null || $product->game_id !== $key->game_id) {
            return null;
        }

        return $product->revenue_model === Product::REVENUE_HOUSE_BANKED ? $product : null;
    }

    private function productNotAllowed(): WagerResult
    {
        return WagerResult::refused('product_not_allowed', 'This key cannot use that product', 403);
    }

    private function findByReference(Product $product, string $reference): ?Wager
    {
        return Wager::where('product_id', $product->id)->where('reference', $reference)->with(['product', 'round'])->first();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function placeLocked(ApiKey $key, Customer $player, Product $product, array $data): WagerResult
    {
        $round = null;

        if (! empty($data['round_reference'])) {
            $round = WagerRound::firstOrCreate(
                ['product_id' => $product->id, 'reference' => $data['round_reference']],
                ['status' => WagerRound::STATUS_OPEN]
            );
            $round = WagerRound::whereKey($round->id)->sharedLock()->first();

            // A non-final round settlement closes the round to places while it is still open
            if ($round->status !== WagerRound::STATUS_OPEN || $round->closed_at !== null) {
                return WagerResult::refused('round_closed', 'This round is closed', 409);
            }
        }

        $isFreeBet = $data['funding'] === Wager::FUNDING_FREE_BET;
        $stakeCents = $isFreeBet ? 0 : $this->cents($data['stake']);

        if ($product->max_stake !== null && $stakeCents > $this->cents($product->max_stake)) {
            return WagerResult::refused('stake_above_limit', 'The stake is above the limit for this product', 422, [
                'max_stake' => (float) $product->max_stake,
            ]);
        }

        $wallet = Wallet::where('customer_id', $player->id)->lockForUpdate()->first()
            ?? Wallet::forceCreate(['customer_id' => $player->id, 'balance' => 0]);

        if (! $isFreeBet && ($this->cents($wallet->balance) < 0 || $this->cents($wallet->balance) < $stakeCents)) {
            return WagerResult::refused('insufficient_funds', 'The balance is too low for this stake', 422);
        }

        $wager = Wager::create([
            'product_id' => $product->id,
            'customer_id' => $player->id,
            'wallet_id' => $wallet->id,
            'wager_round_id' => $round?->id,
            'api_key_id' => $key->id,
            'reference' => $data['reference'],
            'funding' => $data['funding'],
            'stake' => $stakeCents / 100,
            'free_bet_amount' => $isFreeBet ? $this->cents($data['free_bet_amount']) / 100 : 0,
            'balance' => 0,
            'status' => Wager::STATUS_OPEN,
            'channel' => $data['channel'] ?? null,
            'metadata' => $data['metadata'] ?? null,
            'placed_at' => now(),
        ]);

        if (! $isFreeBet) {
            $this->ledger->recordWagerStake($wager, $wallet, $stakeCents / 100);
        }

        return WagerResult::placed($wager->load(['product', 'round']), $this->balanceOf($wallet->id));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function replayOrConflict(Wager $existing, Customer $player, array $data): WagerResult
    {
        if ($existing->is_tombstone) {
            return WagerResult::refused('wager_voided', 'This reference was voided and cannot be used', 409);
        }

        $isFreeBet = $data['funding'] === Wager::FUNDING_FREE_BET;

        $same = $existing->customer_id === $player->id
            && $existing->funding === $data['funding']
            && $this->cents($existing->stake) === ($isFreeBet ? 0 : $this->cents($data['stake']))
            && $this->cents($existing->free_bet_amount) === ($isFreeBet ? $this->cents($data['free_bet_amount']) : 0)
            && $existing->round?->reference === ($data['round_reference'] ?? null)
            && $existing->channel === ($data['channel'] ?? null);

        if (! $same) {
            return WagerResult::refused('reference_conflict', 'This reference was used for a different bet', 409);
        }

        return WagerResult::placed($existing->load(['product', 'round']), $this->balanceOf($existing->wallet_id), true);
    }

    /**
     * Settle a wager as won, lost or void at the next version. Never refused for the house or player balance, and
     * allowed for a suspended product so open wagers can always be closed.
     *
     * @param  array<string, mixed>  $data  version, outcome, payout, bonus?, reason?
     */
    public function settle(ApiKey $key, string $productCode, string $reference, array $data): WagerResult
    {
        $product = $this->allowedProduct($key, $productCode);

        if (! $product) {
            return $this->productNotAllowed();
        }

        $paysNothing = $data['outcome'] !== WagerSettlement::OUTCOME_WON;

        if ($paysNothing && ($this->cents($data['payout']) > 0 || $this->cents($data['bonus'] ?? 0) > 0)) {
            return WagerResult::refused('validation_failed', 'A lost or void settlement must have a payout and bonus of 0', 422);
        }

        $wager = $this->findByReference($product, $reference);

        if (! $wager) {
            return $this->settleUnknownReference($key, $product, $reference, $data);
        }

        return $this->settleExisting($product, $wager, $data);
    }

    /**
     * Take back a wager's current settlement (the exact negation of its legs) and reopen the wager. Never refused for
     * a balance: the player and the house may go negative. The settlement version stays, so the next settle is v+1.
     *
     * @param  array<string, mixed>  $data  version, reason
     */
    public function reverse(ApiKey $key, string $productCode, string $reference, array $data): WagerResult
    {
        $product = $this->allowedProduct($key, $productCode);

        if (! $product) {
            return $this->productNotAllowed();
        }

        $wager = $this->findByReference($product, $reference);

        if (! $wager) {
            return WagerResult::refused('wager_not_found', 'No wager has that reference', 404);
        }

        // Replay and refusals first, unlocked: a retry that already went through moves no money.
        $early = $this->reverseStateResult($wager, (int) $data['version']);

        if ($early) {
            return $early;
        }

        return DB::transaction(fn () => $this->reverseLocked($wager->id, $data), 3);
    }

    /**
     * Replay, or the refusal for the request against the wager's current state; null when it may be reversed.
     */
    private function reverseStateResult(Wager $wager, int $version): ?WagerResult
    {
        if ($wager->is_tombstone) {
            return WagerResult::refused('wager_voided', 'This reference was voided and cannot be reversed', 409);
        }

        $settlement = WagerSettlement::where('wager_id', $wager->id)->where('version', $version)->first();

        if ($wager->status === Wager::STATUS_OPEN) {
            if ($wager->settlement_version === 0) {
                return WagerResult::refused('not_settled', 'This wager has not been settled', 409);
            }

            if ($wager->settlement_version === $version && $settlement?->status === WagerSettlement::STATUS_REVERSED) {
                return WagerResult::settled($wager->loadMissing(['product', 'round']), $settlement, true);
            }
        } elseif ($wager->settlement_version === $version && $settlement?->status === WagerSettlement::STATUS_APPLIED) {
            return $settlement->settled_by === WagerSettlement::SETTLED_BY_GAME
                ? null
                : WagerResult::refused('settlement_conflict', 'This version was settled by an admin', 409, $this->stateOf($wager));
        }

        return WagerResult::refused('version_out_of_order', 'The version is not the wager\'s current settlement', 409, $this->stateOf($wager));
    }

    /**
     * Lock order: wager, player wallet, house wallet. The state is checked again under the wager lock.
     *
     * @param  array<string, mixed>  $data
     */
    private function reverseLocked(int $wagerId, array $data): WagerResult
    {
        $wager = Wager::whereKey($wagerId)->lockForUpdate()->firstOrFail();

        $early = $this->reverseStateResult($wager, (int) $data['version']);

        if ($early) {
            return $early;
        }

        $settlement = WagerSettlement::where('wager_id', $wager->id)->where('version', $wager->settlement_version)->firstOrFail();
        $isCash = $wager->funding === Wager::FUNDING_CASH;
        $won = $settlement->outcome === WagerSettlement::OUTCOME_WON;

        $needsPlayer = $won || ($settlement->outcome === WagerSettlement::OUTCOME_VOID && $isCash);
        $needsHouse = $won || ($settlement->outcome === WagerSettlement::OUTCOME_LOST && $isCash);

        // The legs move money on these locked instances: a plain re-read could return this transaction's older
        // snapshot (fixed by the settlement read above) and overwrite a balance committed while we waited.
        $player = $needsPlayer ? Wallet::whereKey($wager->wallet_id)->lockForUpdate()->firstOrFail() : null;
        $house = $needsHouse ? Wallet::whereKey(config('wallets.house_wallet_id', 1))->lockForUpdate()->firstOrFail() : null;

        $reversals = $this->ledger->reverseWagerEntries($settlement, $wager, $player, $house);

        foreach ($reversals as $reversal) {
            if ($reversal->entry_type === 'winnings_tax_reversal') {
                $this->winningsTax->recordReversal($settlement, $wager, $reversal);
            }
        }

        $settlement->update([
            'status' => WagerSettlement::STATUS_REVERSED,
            'reversed_at' => now(),
            'reversal_reason' => $data['reason'],
        ]);

        $wager->update([
            'status' => Wager::STATUS_OPEN,
            'payout' => 0,
            'bonus' => 0,
            'tax' => 0,
            'settled_at' => null,
        ]);

        return WagerResult::settled($wager->fresh()->load(['product', 'round']), $settlement);
    }

    /**
     * Void a stuck open wager on an admin's say-so: a void at the next version, settled by the admin, refunding a
     * cash stake exactly as a game's void does. Only an open wager (a reopened one too) can be voided; anything
     * else is refused with its state. Lock order: wager, player wallet.
     */
    public function adminVoid(Wager $wager, string $reason, string $adminUser): WagerResult
    {
        $early = $this->adminVoidRefusal($wager);

        if ($early) {
            return $early;
        }

        return DB::transaction(fn () => $this->adminVoidLocked($wager->id, $reason, $adminUser), 3);
    }

    private function adminVoidRefusal(Wager $wager): ?WagerResult
    {
        if ($wager->is_tombstone || $wager->status !== Wager::STATUS_OPEN) {
            return WagerResult::refused('wager_not_open', 'Only an open wager can be voided', 409, $this->stateOf($wager));
        }

        return null;
    }

    private function adminVoidLocked(int $wagerId, string $reason, string $adminUser): WagerResult
    {
        $wager = Wager::whereKey($wagerId)->lockForUpdate()->firstOrFail();

        $early = $this->adminVoidRefusal($wager);

        if ($early) {
            return $early;
        }

        ['player' => $needsPlayer] = $this->walletsMoved($wager, WagerSettlement::OUTCOME_VOID);

        $player = $needsPlayer ? Wallet::whereKey($wager->wallet_id)->lockForUpdate()->firstOrFail() : null;

        $settlement = $this->applySettlement($wager->product, $wager, $player, null, [
            'version' => $wager->settlement_version + 1,
            'outcome' => WagerSettlement::OUTCOME_VOID,
            'payout' => 0,
            'reason' => $reason,
            'settled_by' => WagerSettlement::SETTLED_BY_ADMIN,
            'admin_user' => $adminUser,
        ]);

        return WagerResult::settled($wager->load(['product', 'round']), $settlement);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function settleUnknownReference(ApiKey $key, Product $product, string $reference, array $data): WagerResult
    {
        if ($data['outcome'] !== WagerSettlement::OUTCOME_VOID) {
            return WagerResult::refused('wager_not_found', 'No wager has that reference', 404);
        }

        try {
            return DB::transaction(fn () => $this->createTombstone($key, $product, $reference, $data));
        } catch (UniqueConstraintViolationException) {
            // A racing request created the reference first: the unique key decides, the loser reads the winner's row.
            $wager = $this->findByReference($product, $reference);

            return $wager
                ? $this->settleExisting($product, $wager, $data)
                : WagerResult::refused('server_error', 'Please retry', 500);
        }
    }

    /**
     * A void for a bet that was never placed. The row only blocks a later place of that reference.
     *
     * @param  array<string, mixed>  $data
     */
    private function createTombstone(ApiKey $key, Product $product, string $reference, array $data): WagerResult
    {
        $wager = Wager::create([
            'product_id' => $product->id,
            'api_key_id' => $key->id,
            'reference' => $reference,
            'funding' => Wager::FUNDING_CASH,
            'stake' => 0,
            'balance' => 0,
            'status' => Wager::STATUS_VOID,
            'is_tombstone' => true,
            'settlement_version' => $data['version'],
            'settled_at' => now(),
        ]);

        $settlement = $wager->settlements()->create($this->settlementAttributes($data, 0, 0, 0, 0));

        return WagerResult::settled($wager->load(['product', 'round']), $settlement, created: true);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function settleExisting(Product $product, Wager $wager, array $data): WagerResult
    {
        // Replay and state checks first, unlocked: a retry that already went through moves no money.
        $early = $this->settleStateResult($wager, $data);

        if ($early) {
            return $early;
        }

        if ($product->max_payout !== null
            && $this->cents($data['payout']) + $this->cents($data['bonus'] ?? 0) > $this->cents($product->max_payout)) {
            return WagerResult::refused('payout_above_limit', 'The payout and bonus are above the limit for this product', 422, [
                'max_payout' => (float) $product->max_payout,
            ]);
        }

        return DB::transaction(fn () => $this->settleLocked($product, $wager->id, $data), 3);
    }

    /**
     * Replay, conflict or out-of-order for the request against the wager's current state; null when it may be applied.
     * A version the admin settled is always a conflict for the game, even with the same payload.
     *
     * @param  array<string, mixed>  $data
     */
    private function settleStateResult(Wager $wager, array $data): ?WagerResult
    {
        $version = (int) $data['version'];
        $existing = WagerSettlement::where('wager_id', $wager->id)->where('version', $version)->first();

        if ($existing && $existing->status === WagerSettlement::STATUS_APPLIED) {
            $same = $existing->settled_by === WagerSettlement::SETTLED_BY_GAME
                && $existing->outcome === $data['outcome']
                && $this->cents($existing->payout) === $this->cents($data['payout'])
                && $this->cents($existing->bonus) === $this->cents($data['bonus'] ?? 0);

            return $same
                ? WagerResult::settled($wager->loadMissing(['product', 'round']), $existing, true)
                : WagerResult::refused('settlement_conflict', 'This version was settled differently', 409, $this->stateOf($wager));
        }

        if ($wager->status !== Wager::STATUS_OPEN || $version !== $wager->settlement_version + 1) {
            return WagerResult::refused('version_out_of_order', 'The version does not follow the wager\'s current settlement', 409, $this->stateOf($wager));
        }

        return null;
    }

    /**
     * @return array{status: string, settlement_version: int}
     */
    private function stateOf(Wager $wager): array
    {
        return ['status' => $wager->status, 'settlement_version' => (int) $wager->settlement_version];
    }

    /**
     * Lock order: wager, player wallet, house wallet. The state is checked again under the wager lock.
     *
     * @param  array<string, mixed>  $data
     */
    private function settleLocked(Product $product, int $wagerId, array $data): WagerResult
    {
        $wager = Wager::whereKey($wagerId)->lockForUpdate()->firstOrFail();

        $early = $this->settleStateResult($wager, $data);

        if ($early) {
            return $early;
        }

        ['player' => $needsPlayer, 'house' => $needsHouse] = $this->walletsMoved($wager, $data['outcome']);

        $player = $needsPlayer ? Wallet::whereKey($wager->wallet_id)->lockForUpdate()->firstOrFail() : null;
        $house = $needsHouse ? Wallet::whereKey(config('wallets.house_wallet_id', 1))->lockForUpdate()->firstOrFail() : null;

        $settlement = $this->applySettlement($product, $wager, $player, $house, $data);

        return WagerResult::settled($wager->load(['product', 'round']), $settlement);
    }

    /**
     * Which wallets a settlement with this outcome moves money on: the player's for a win or a cash refund, the
     * house's for a win or a cash loss. A lost or void free bet moves nothing.
     *
     * @return array{player: bool, house: bool}
     */
    public function walletsMoved(Wager $wager, string $outcome): array
    {
        $isFreeBet = $wager->funding === Wager::FUNDING_FREE_BET;

        return [
            'player' => $outcome === WagerSettlement::OUTCOME_WON || ($outcome === WagerSettlement::OUTCOME_VOID && ! $isFreeBet),
            'house' => $outcome === WagerSettlement::OUTCOME_WON || ($outcome === WagerSettlement::OUTCOME_LOST && ! $isFreeBet),
        ];
    }

    /**
     * Settle one wager on instances the caller already locked (the wager, and the wallets `walletsMoved()` names):
     * the tax, the settlement row, its legs in section 5.4's order, and the wager's new state. Shared by the
     * per-wager settle and the round settle and void; the caller has checked that the version may be applied.
     *
     * @param  array<string, mixed>  $data  version, outcome, payout, bonus?, reason?
     */
    public function applySettlement(Product $product, Wager $wager, ?Wallet $player, ?Wallet $house, array $data): WagerSettlement
    {
        $outcome = $data['outcome'];
        $isFreeBet = $wager->funding === Wager::FUNDING_FREE_BET;
        $payout = $this->cents($data['payout']);
        $bonus = $this->cents($data['bonus'] ?? 0);
        $stake = $this->cents($wager->stake);

        $rate = (string) ($product->winnings_tax_rate ?? '0');
        $taxBase = $outcome === WagerSettlement::OUTCOME_WON ? ($isFreeBet ? $payout + $bonus : max(0, $payout + $bonus - $stake)) : 0;
        $tax = $this->taxCents($taxBase, $rate);

        $settlement = $wager->settlements()->create($this->settlementAttributes($data, $payout, $bonus, $tax, $taxBase, $rate));

        $this->postSettlementLegs($settlement, $wager, $player, $house, $payout / 100, $bonus / 100, $tax / 100);

        $wager->update([
            'status' => $outcome,
            'settlement_version' => $settlement->version,
            'payout' => $payout / 100,
            'bonus' => $bonus / 100,
            'tax' => $tax / 100,
            'settled_at' => now(),
        ]);

        return $settlement;
    }

    /**
     * The legs of section 5.4 in posting order. Zero amounts are never posted.
     */
    private function postSettlementLegs(WagerSettlement $settlement, Wager $wager, ?Wallet $player, ?Wallet $house, float $payout, float $bonus, float $tax): void
    {
        $isFreeBet = $wager->funding === Wager::FUNDING_FREE_BET;
        $stake = (float) $wager->stake;

        if ($settlement->outcome === WagerSettlement::OUTCOME_VOID) {
            if (! $isFreeBet && $stake > 0) {
                $this->ledger->recordWagerRefund($settlement, $wager, $player, $stake);
            }

            return;
        }

        if (! $isFreeBet && $stake > 0) {
            $this->ledger->recordWagerStakeSettled($settlement, $wager, $house, $stake);
        }

        if ($settlement->outcome === WagerSettlement::OUTCOME_LOST) {
            return;
        }

        if ($payout > 0) {
            $isFreeBet
                ? $this->ledger->recordWagerFreeBetPayout($settlement, $house, $player, $payout)
                : $this->ledger->recordWagerPayout($settlement, $house, $player, $payout);
        }

        if ($bonus > 0) {
            $this->ledger->recordWagerBonus($settlement, $house, $player, $bonus);
        }

        if ($tax > 0) {
            $this->winningsTax->recordCharge($settlement, $wager, $this->ledger->recordWinningsTax($settlement, $player, $tax));
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function settlementAttributes(array $data, int $payout, int $bonus, int $tax, int $taxBase, string $rate = '0'): array
    {
        return [
            'version' => $data['version'],
            'outcome' => $data['outcome'],
            'payout' => $payout / 100,
            'bonus' => $bonus / 100,
            'tax' => $tax / 100,
            'tax_base' => $taxBase / 100,
            'tax_rate' => $rate,
            'reason' => $data['reason'] ?? null,
            'settled_by' => $data['settled_by'] ?? WagerSettlement::SETTLED_BY_GAME,
            'admin_user' => $data['admin_user'] ?? null,
            'status' => WagerSettlement::STATUS_APPLIED,
        ];
    }

    /**
     * @return array{balance: float, wallet_version: int}
     */
    private function balanceOf(int $walletId): array
    {
        $wallet = Wallet::findOrFail($walletId);

        return ['balance' => (float) $wallet->balance, 'wallet_version' => (int) $wallet->wallet_version];
    }

    /**
     * Tax in whole cents, all in integers: the base in cents times the rate in basis points (the rate column has
     * 4 decimals), rounded half up. A float product can land just under a half cent (0.90 × 0.35) and round down.
     */
    private function taxCents(int $baseCents, string $rate): int
    {
        [$whole, $fraction] = array_pad(explode('.', trim($rate), 2), 2, '');
        $basisPoints = (int) $whole * 10000 + (int) str_pad(substr($fraction, 0, 4), 4, '0');

        return intdiv($baseCents * $basisPoints + 5000, 10000);
    }

    private function cents(float|int|string|null $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }
}
