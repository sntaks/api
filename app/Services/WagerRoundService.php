<?php

namespace App\Services;

use App\Models\ApiKey;
use App\Models\Product;
use App\Models\Wager;
use App\Models\WagerRound;
use App\Models\WagerSettlement;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Settles or voids the wagers of a round (a crash round) together. The round row is locked exclusively in every
 * transaction of a call, so no place can join it meanwhile (a place takes it shared). The work runs in transactions
 * of at most `$chunkSize` wagers, each locking in the order round, wagers (by id), player wallets (by id), house
 * wallet. The first transaction closes the round, so a call that dies between chunks is finished by a rerun.
 * Every transaction re-checks the locked round's status: a non-final settle leaves the round open, so a void can
 * land between its transactions, and the settle then stops with `round_closed` instead of paying into it.
 *
 * Only an eligible wager is ever touched: one of this round, open, never settled (version 0) and not a tombstone.
 * A wager that was settled and then reversed back to open is left for the game to settle per wager. Every
 * settlement a round writes is version 1 by the game, posted through `WagerService::applySettlement()`.
 */
class WagerRoundService
{
    public function __construct(private WagerService $wagers, private int $chunkSize = 500) {}

    /**
     * Settle the listed winners as won and, for a final call, every other eligible wager of the round as lost.
     * A non-final call only closes the round to new places. The winners are checked before any money moves.
     *
     * @param  list<array{reference: string, payout: float|int|string, bonus?: float|int|string|null}>  $winners
     */
    public function settle(ApiKey $key, string $productCode, string $roundReference, array $winners, bool $final = true): WagerRoundResult
    {
        $product = $this->wagers->allowedProduct($key, $productCode);

        if (! $product) {
            return $this->productNotAllowed();
        }

        $aboveLimit = $this->aboveMaxPayout($product, $winners);

        if ($aboveLimit !== []) {
            return WagerRoundResult::refused('payout_above_limit', 'A winner\'s payout and bonus are above the limit for this product', 422, [
                'max_payout' => (float) $product->max_payout,
                'references' => $aboveLimit,
            ]);
        }

        $round = $this->findOrCreateRound($product, $roundReference);
        $listed = $this->listedWagers($product, $winners);

        $opened = DB::transaction(fn () => $this->openSettle($round->id, $winners, $listed, $final), 3);

        if ($opened instanceof WagerRoundResult) {
            return $opened;
        }

        $counts = $this->emptyCounts($opened['already_settled']);
        $skippedWinners = [];

        foreach (array_chunk($opened['to_settle'], $this->chunkSize, true) as $chunk) {
            // A non-final batch leaves the round open, so a void can land between its transactions
            $done = DB::transaction(function () use ($product, $round, $chunk) {
                $locked = $this->lockRound($round->id, WagerRound::STATUS_VOIDED);

                return $locked instanceof WagerRoundResult ? $locked : $this->settleWagers($product, $round->id, $chunk);
            }, 3);

            if ($done instanceof WagerRoundResult) {
                return $done;
            }

            // A winner that stopped being eligible after the opening check (an admin void, or the game's own settle)
            // was not paid by this call: it is reported, not counted as already settled
            $counts['won'] += $done['settled'];
            $skippedWinners = [...$skippedWinners, ...array_map(fn (int $id) => $opened['references'][$id], $done['skipped_ids'])];
        }

        if ($final) {
            $done = $this->settleEligible($product, $round->id, $this->settlementData(WagerSettlement::OUTCOME_LOST), $listed->pluck('id')->all(), WagerRound::STATUS_VOIDED);

            if ($done instanceof WagerRoundResult) {
                return $done;
            }

            $counts['lost'] += $done['settled'];
            $counts['already_settled'] += $done['skipped'];
        }

        return $this->finish($product, $round->id, $counts, $skippedWinners);
    }

    /**
     * Void every eligible wager of the round (cash stakes go back to the players) and mark the round voided.
     */
    public function void(ApiKey $key, string $productCode, string $roundReference, ?string $reason = null): WagerRoundResult
    {
        $product = $this->wagers->allowedProduct($key, $productCode);

        if (! $product) {
            return $this->productNotAllowed();
        }

        $round = $this->findOrCreateRound($product, $roundReference);

        $opened = DB::transaction(fn () => $this->openVoid($round->id), 3);

        if ($opened instanceof WagerRoundResult) {
            return $opened;
        }

        $counts = $this->emptyCounts($opened);

        $done = $this->settleEligible($product, $round->id, $this->settlementData(WagerSettlement::OUTCOME_VOID, reason: $reason), [], WagerRound::STATUS_SETTLED);

        if ($done instanceof WagerRoundResult) {
            return $done;
        }

        $counts['voided'] += $done['settled'];
        $counts['already_settled'] += $done['skipped'];

        return $this->finish($product, $round->id, $counts);
    }

    private function productNotAllowed(): WagerRoundResult
    {
        return WagerRoundResult::refused('product_not_allowed', 'This key cannot use that product', 403);
    }

    /**
     * Created through the unique key when nobody bet on it (no FOR UPDATE on a row that may not exist), so a round
     * that closes empty still refuses a late place.
     */
    private function findOrCreateRound(Product $product, string $reference): WagerRound
    {
        return WagerRound::firstOrCreate(
            ['product_id' => $product->id, 'reference' => $reference],
            ['status' => WagerRound::STATUS_OPEN]
        );
    }

    /**
     * The product's wagers with the listed references, by a plain read keyed by reference (case-insensitive, like
     * the column). Only rows that exist are locked later, by id.
     *
     * @param  list<array{reference: string, payout: float|int|string, bonus?: float|int|string|null}>  $winners
     * @return Collection<string, Wager>
     */
    private function listedWagers(Product $product, array $winners): Collection
    {
        if ($winners === []) {
            return collect();
        }

        return Wager::where('product_id', $product->id)
            ->whereIn('reference', array_column($winners, 'reference'))
            ->get(['id', 'reference'])
            ->keyBy(fn (Wager $wager) => $this->referenceKey($wager->reference));
    }

    /**
     * The first transaction of a settle: lock the round, check every winner on its locked row, then close the
     * round. A refusal writes nothing.
     *
     * @param  list<array{reference: string, payout: float|int|string, bonus?: float|int|string|null}>  $winners
     * @param  Collection<string, Wager>  $listed
     * @return array{to_settle: array<int, array<string, mixed>>, references: array<int, string>, already_settled: int}|WagerRoundResult
     */
    private function openSettle(int $roundId, array $winners, Collection $listed, bool $final): array|WagerRoundResult
    {
        $round = $this->lockRound($roundId, WagerRound::STATUS_VOIDED);

        if ($round instanceof WagerRoundResult) {
            return $round;
        }

        $locked = $listed->isEmpty()
            ? collect()
            : Wager::whereKey($listed->pluck('id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $firstSettlements = $locked->isEmpty()
            ? collect()
            : WagerSettlement::whereIn('wager_id', $locked->keys())->where('version', 1)->get()->keyBy('wager_id');

        $notInRound = [];
        $conflicts = [];
        $toSettle = [];
        $references = [];

        foreach ($winners as $winner) {
            $wagerId = $listed->get($this->referenceKey($winner['reference']))?->id;
            $wager = $wagerId === null ? null : $locked->get($wagerId);

            if (! $wager || (int) $wager->wager_round_id !== $round->id) {
                $notInRound[] = $winner['reference'];
            } elseif ($this->isEligible($wager, $round->id)) {
                $toSettle[$wager->id] = $this->settlementData(WagerSettlement::OUTCOME_WON, $winner['payout'], $winner['bonus'] ?? 0);
                $references[$wager->id] = $winner['reference'];
            } elseif (! $this->isSameWin($wager, $firstSettlements->get($wager->id), $winner)) {
                $conflicts[] = $winner['reference'];
            }
        }

        if ($notInRound !== []) {
            return WagerRoundResult::refused('wager_not_in_round', 'Some winners are not wagers of this round', 422, ['references' => $notInRound]);
        }

        if ($conflicts !== []) {
            return WagerRoundResult::refused('settlement_conflict', 'Some winners were already settled differently', 409, ['references' => $conflicts]);
        }

        $this->close($round, $final ? WagerRound::STATUS_SETTLED : null);

        return ['to_settle' => $toSettle, 'references' => $references, 'already_settled' => $this->notOpenCount($round->id)];
    }

    /**
     * The first transaction of a void: lock the round, refuse a settled one, then close it as voided.
     */
    private function openVoid(int $roundId): int|WagerRoundResult
    {
        $round = $this->lockRound($roundId, WagerRound::STATUS_SETTLED);

        if ($round instanceof WagerRoundResult) {
            return $round;
        }

        $this->close($round, WagerRound::STATUS_VOIDED);

        return $this->notOpenCount($round->id);
    }

    /**
     * Lock the round exclusively, as every transaction of a call does first, and refuse with `round_closed` when it
     * has the status this call cannot follow: `voided` for a settle, `settled` for a void.
     */
    private function lockRound(int $roundId, string $refusedStatus): WagerRound|WagerRoundResult
    {
        $round = WagerRound::whereKey($roundId)->lockForUpdate()->firstOrFail();

        if ($round->status !== $refusedStatus) {
            return $round;
        }

        return $refusedStatus === WagerRound::STATUS_VOIDED
            ? WagerRoundResult::refused('round_closed', 'This round was voided and cannot be settled', 409)
            : WagerRoundResult::refused('round_closed', 'This round was settled and cannot be voided', 409);
    }

    /**
     * Set `closed_at` once and, for a final settle or a void, the terminal status. A terminal status never changes.
     */
    private function close(WagerRound $round, ?string $terminalStatus): void
    {
        $changes = [];

        if ($round->closed_at === null) {
            $changes['closed_at'] = now();
        }

        if ($terminalStatus !== null && $round->status === WagerRound::STATUS_OPEN) {
            $changes['status'] = $terminalStatus;
        }

        if ($changes !== []) {
            $round->update($changes);
        }
    }

    /**
     * Settle every eligible wager of the round (except the listed winners) with the same outcome, one chunk per
     * transaction, walking the ids upwards so every chunk makes progress.
     *
     * Stops with the `round_closed` refusal if the locked round has `$refusedStatus`. The opening transaction already
     * set this call's terminal status and a terminal status never changes, so this is a guard, not an expected path.
     *
     * @param  array<string, mixed>  $data
     * @param  list<int>  $excludedIds
     * @return array{settled: int, skipped: int}|WagerRoundResult
     */
    private function settleEligible(Product $product, int $roundId, array $data, array $excludedIds, string $refusedStatus): array|WagerRoundResult
    {
        $total = ['settled' => 0, 'skipped' => 0];
        $afterId = 0;

        do {
            $done = DB::transaction(function () use ($product, $roundId, $data, $excludedIds, $afterId, $refusedStatus) {
                $round = $this->lockRound($roundId, $refusedStatus);

                if ($round instanceof WagerRoundResult) {
                    return $round;
                }

                // A plain read for the ids (no range lock on the round index); the rows are locked by id and re-checked
                $ids = $this->eligible($roundId)
                    ->where('id', '>', $afterId)
                    ->whereNotIn('id', $excludedIds)
                    ->orderBy('id')
                    ->limit($this->chunkSize)
                    ->pluck('id')
                    ->all();

                return ['ids' => $ids] + $this->settleWagers($product, $roundId, array_fill_keys($ids, $data));
            }, 3);

            if ($done instanceof WagerRoundResult) {
                return $done;
            }

            $total['settled'] += $done['settled'];
            $total['skipped'] += $done['skipped'];
            $afterId = $done['ids'] === [] ? $afterId : max($done['ids']);
        } while (count($done['ids']) === $this->chunkSize);

        return $total;
    }

    /**
     * Lock the wagers by id, re-check each is still eligible, lock the player wallets their legs move (by id) and
     * then the house wallet, and post every settlement on those locked instances. The caller holds the round lock.
     * A wager that stopped being eligible since it was read (settled per wager meanwhile) is skipped.
     *
     * @param  array<int, array<string, mixed>>  $settlements  wager id => version, outcome, payout, bonus, reason
     * @return array{settled: int, skipped: int, skipped_ids: list<int>}
     */
    private function settleWagers(Product $product, int $roundId, array $settlements): array
    {
        if ($settlements === []) {
            return ['settled' => 0, 'skipped' => 0, 'skipped_ids' => []];
        }

        $wagers = Wager::whereKey(array_keys($settlements))->orderBy('id')->lockForUpdate()->get()
            ->filter(fn (Wager $wager) => $this->isEligible($wager, $roundId))
            ->values();

        $moves = $wagers->mapWithKeys(fn (Wager $wager) => [$wager->id => $this->wagers->walletsMoved($wager, $settlements[$wager->id]['outcome'])]);

        $playerWalletIds = $wagers->filter(fn (Wager $wager) => $moves[$wager->id]['player'])->pluck('wallet_id')->unique()->sort()->values();
        $players = $playerWalletIds->isEmpty()
            ? collect()
            : Wallet::whereKey($playerWalletIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        $house = null;

        if ($moves->contains(fn (array $move) => $move['house'])) {
            $houseId = (int) config('wallets.house_wallet_id', 1);
            // Never two instances of one row: a second instance would overwrite the first one's balance
            $house = $players->get($houseId) ?? Wallet::whereKey($houseId)->lockForUpdate()->firstOrFail();
        }

        foreach ($wagers as $wager) {
            $move = $moves[$wager->id];

            $this->wagers->applySettlement(
                $product,
                $wager,
                $move['player'] ? $players->get($wager->wallet_id) : null,
                $move['house'] ? $house : null,
                $settlements[$wager->id],
            );
        }

        $skippedIds = array_values(array_diff(array_keys($settlements), $wagers->pluck('id')->all()));

        return ['settled' => $wagers->count(), 'skipped' => count($skippedIds), 'skipped_ids' => $skippedIds];
    }

    /**
     * @param  array{won: int, lost: int, voided: int, already_settled: int, left_open: int}  $counts
     * @param  list<string>|null  $skippedWinners  null for a void, which has no winners
     */
    private function finish(Product $product, int $roundId, array $counts, ?array $skippedWinners = null): WagerRoundResult
    {
        $counts['left_open'] = Wager::where('wager_round_id', $roundId)
            ->where('status', Wager::STATUS_OPEN)
            ->where('settlement_version', '>', 0)
            ->where('is_tombstone', false)
            ->count();

        return WagerRoundResult::closed(WagerRound::findOrFail($roundId)->setRelation('product', $product), $counts, $skippedWinners);
    }

    /**
     * @return Builder<Wager>
     */
    private function eligible(int $roundId): Builder
    {
        return Wager::where('wager_round_id', $roundId)
            ->where('status', Wager::STATUS_OPEN)
            ->where('settlement_version', 0)
            ->where('is_tombstone', false);
    }

    private function isEligible(Wager $wager, int $roundId): bool
    {
        return (int) $wager->wager_round_id === $roundId
            && $wager->status === Wager::STATUS_OPEN
            && (int) $wager->settlement_version === 0
            && ! $wager->is_tombstone;
    }

    /**
     * A winner a previous call already settled exactly so: won at version 1 by the game, same payout and bonus.
     *
     * @param  array{reference: string, payout: float|int|string, bonus?: float|int|string|null}  $winner
     */
    private function isSameWin(Wager $wager, ?WagerSettlement $settlement, array $winner): bool
    {
        return $wager->status === Wager::STATUS_WON
            && (int) $wager->settlement_version === 1
            && $settlement !== null
            && $settlement->status === WagerSettlement::STATUS_APPLIED
            && $settlement->settled_by === WagerSettlement::SETTLED_BY_GAME
            && $settlement->outcome === WagerSettlement::OUTCOME_WON
            && $this->cents($settlement->payout) === $this->cents($winner['payout'])
            && $this->cents($settlement->bonus) === $this->cents($winner['bonus'] ?? 0);
    }

    private function notOpenCount(int $roundId): int
    {
        return Wager::where('wager_round_id', $roundId)
            ->where('status', '!=', Wager::STATUS_OPEN)
            ->where('is_tombstone', false)
            ->count();
    }

    /**
     * @param  list<array{reference: string, payout: float|int|string, bonus?: float|int|string|null}>  $winners
     * @return list<string> the references whose payout and bonus are above the product's max_payout
     */
    private function aboveMaxPayout(Product $product, array $winners): array
    {
        if ($product->max_payout === null) {
            return [];
        }

        $limit = $this->cents($product->max_payout);

        return array_values(array_map(
            fn (array $winner) => $winner['reference'],
            array_filter($winners, fn (array $winner) => $this->cents($winner['payout']) + $this->cents($winner['bonus'] ?? 0) > $limit)
        ));
    }

    /**
     * @return array<string, mixed> a version 1 settlement as `WagerService::applySettlement()` takes it
     */
    private function settlementData(string $outcome, float|int|string $payout = 0, float|int|string|null $bonus = 0, ?string $reason = null): array
    {
        return ['version' => 1, 'outcome' => $outcome, 'payout' => $payout, 'bonus' => $bonus ?? 0, 'reason' => $reason];
    }

    /**
     * @return array{won: int, lost: int, voided: int, already_settled: int, left_open: int}
     */
    private function emptyCounts(int $alreadySettled): array
    {
        return ['won' => 0, 'lost' => 0, 'voided' => 0, 'already_settled' => $alreadySettled, 'left_open' => 0];
    }

    private function referenceKey(string $reference): string
    {
        return mb_strtolower($reference);
    }

    private function cents(float|int|string|null $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }
}
