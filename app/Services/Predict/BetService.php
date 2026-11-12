<?php

namespace App\Services\Predict;

use App\Models\Customer;
use App\Models\PredictBet;
use App\Models\PredictMarket;
use App\Models\PredictMarketBlock;
use App\Models\PredictQuote;
use App\Models\Wager;
use App\Services\Predict\Engines\EngineRegistry;
use App\Services\WagerService;
use Illuminate\Support\Facades\DB;

/**
 * Places a player's bet on a market as one transaction: the market (shared lock, so no admin change lands
 * mid-bet), the engine's state (locked by the engine), then WagerService::place, which locks the round, the
 * player's wallet and posts the stake. Lock order: market → pool → round → wager → player wallet → house.
 *
 * Idempotent by the caller's reference, like the wager API: a retry with the same terms replays, anything else
 * under that reference is a conflict.
 */
class BetService
{
    public function __construct(
        private PredictContext $context,
        private EngineRegistry $engines,
        private WagerService $wagers,
    ) {}

    /**
     * @param  array{side: string, stake: float|int|string, reference: string, quote_id?: ?string}  $data
     */
    public function place(Customer $customer, PredictMarket $market, array $data): PredictResult
    {
        $stakeMinor = (int) round(((float) $data['stake']) * 100);
        $existing = $this->existingWager($market, $data['reference']);

        if ($existing) {
            return $this->replayOrConflict($existing, $customer, $market, $data['side'], $stakeMinor);
        }

        $refusal = $this->refusalBeforeLock($customer, $market, $stakeMinor);

        if ($refusal) {
            return $refusal;
        }

        $quote = isset($data['quote_id']) ? PredictQuote::find($data['quote_id']) : null;

        if (isset($data['quote_id']) && ! $quote) {
            return PredictResult::refused('quote_mismatch', 'No quote has that id', 409);
        }

        return DB::transaction(fn () => $this->placeLocked($customer, $market->id, $data['side'], $stakeMinor, $data['reference'], $quote), 3);
    }

    /**
     * The estimated multiplier for a stake now (no lock, nothing recorded).
     */
    public function estimate(PredictMarket $market, string $side, float|int|string $stake): PredictResult
    {
        $stakeMinor = (int) round(((float) $stake) * 100);
        $terms = $this->engines->for($market)->quote($market, $side, $stakeMinor);

        if ($terms->isRefused()) {
            return PredictResult::refused($terms->refusalCode, $terms->refusalMessage, $terms->refusalStatus, $terms->extra);
        }

        return PredictResult::ok([
            'side' => $side,
            'stake' => $stakeMinor / 100,
            'estimated_multiplier' => $terms->multiplierX100 / 100,
            'estimated_payout' => PoolMath::payoutMinor($stakeMinor, $terms->multiplierX100, $market->max_payout_minor) / 100,
            // A preview, never binding: pool odds move until close, and fixed odds hold only through a quote
            'is_estimate' => true,
        ]);
    }

    private function placeLocked(Customer $customer, int $marketId, string $side, int $stakeMinor, string $reference, ?PredictQuote $quote): PredictResult
    {
        $market = PredictMarket::whereKey($marketId)->with('product')->sharedLock()->firstOrFail();
        $closed = $this->closedRefusal($market);

        if ($closed) {
            return $closed;
        }

        $placed = $this->engines->for($market)->placeBet($market, $side, $stakeMinor, fn () => $this->wagers->place($this->context->wagerKey(), $customer, [
            'product' => $market->product->code,
            'reference' => $reference,
            'round_reference' => $market->round_reference,
            'funding' => Wager::FUNDING_CASH,
            'stake' => bcdiv((string) $stakeMinor, '100', 2),
            'channel' => 'predict',
            'metadata' => ['predict_market_id' => $market->id, 'side' => $side],
        ]), $quote, $customer);

        $terms = $placed['terms'];

        if ($terms->isRefused()) {
            return PredictResult::refused($terms->refusalCode, $terms->refusalMessage, $terms->refusalStatus, $terms->extra);
        }

        $wager = $placed['wager'];

        if ($wager->isRefused()) {
            return PredictResult::refused($wager->refusal['code'], $wager->refusal['message'], $wager->refusal['status'], $wager->refusal['extra']);
        }

        if ($wager->replayed) {
            return $this->replayOrConflict($wager->wager, $customer, $market, $side, $stakeMinor);
        }

        $bet = PredictBet::create([
            'predict_market_id' => $market->id,
            'wager_id' => $wager->wager->id,
            'customer_id' => $customer->id,
            'reference' => $reference,
            'side' => $side,
            'currency' => config('predict.currency'),
            'stake_minor' => $stakeMinor,
            'estimated_multiplier_x100' => $terms->multiplierX100,
            'status' => PredictBet::STATUS_OPEN,
            'placed_at' => now(),
        ]);

        if ($quote) {
            PredictQuote::whereKey($quote->id)->update(['used_at' => now(), 'predict_bet_id' => $bet->id]);
        }

        return PredictResult::ok(['bet' => $bet->setRelation('market', $market), 'balance' => $wager->balance], created: true);
    }

    /**
     * Refusals that need no lock: the market must be taking bets, the player not blocked on it, and the stake
     * inside the market's limits. Also used before quoting. Rechecked state (status, close time) happens under the lock.
     */
    public function refusalBeforeLock(Customer $customer, PredictMarket $market, int $stakeMinor): ?PredictResult
    {
        $closed = $this->closedRefusal($market);

        if ($closed) {
            return $closed;
        }

        if (PredictMarketBlock::where('predict_market_id', $market->id)->where('customer_id', $customer->id)->exists()) {
            return PredictResult::refused('player_blocked', 'This player cannot bet on this market', 403);
        }

        if ($stakeMinor < $market->min_stake_minor) {
            return PredictResult::refused('stake_below_limit', 'The stake is below the minimum for this market', 422, ['min_stake' => $market->min_stake_minor / 100]);
        }

        if ($stakeMinor > $market->max_stake_minor) {
            return PredictResult::refused('stake_above_limit', 'The stake is above the limit for this market', 422, ['max_stake' => $market->max_stake_minor / 100]);
        }

        return null;
    }

    /**
     * `market_not_open` unless the market is open; `betting_closed` once its betting cut-off passed (the close
     * time for a pool market, the game start or 15 minutes before close for an imported one).
     */
    private function closedRefusal(PredictMarket $market): ?PredictResult
    {
        if ($market->status !== PredictMarket::STATUS_OPEN || $market->close_at->isPast()) {
            return PredictResult::refused('market_not_open', 'This market is not taking bets', 409, ['status' => $market->status]);
        }

        if (! $market->takesBets()) {
            return PredictResult::refused('betting_closed', 'Betting on this market has closed', 409, [
                'betting_closes_at' => $market->bettingClosesAt()->toIso8601String(),
            ]);
        }

        return null;
    }

    private function existingWager(PredictMarket $market, string $reference): ?Wager
    {
        return Wager::where('product_id', $market->product_id)->where('reference', $reference)->first();
    }

    private function replayOrConflict(Wager $wager, Customer $customer, PredictMarket $market, string $side, int $stakeMinor): PredictResult
    {
        $bet = PredictBet::where('wager_id', $wager->id)->first();

        $isSame = $bet
            && $bet->customer_id === $customer->id
            && $bet->predict_market_id === $market->id
            && $bet->side === $side
            && $bet->stake_minor === $stakeMinor;

        if (! $isSame) {
            return PredictResult::refused('reference_conflict', 'This reference was already used for a different bet', 409);
        }

        return PredictResult::ok(['bet' => $bet->load('market'), 'balance' => null], replayed: true);
    }
}
