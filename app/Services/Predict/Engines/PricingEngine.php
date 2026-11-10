<?php

namespace App\Services\Predict\Engines;

use App\Models\PredictMarket;
use App\Services\WagerResult;
use Closure;

/**
 * How a market prices bets and works out who is paid what. An engine never moves money: placing goes through
 * the caller's wager closure and settling returns a plan the settlement service pays through the wager round.
 */
interface PricingEngine
{
    /**
     * The terms a stake would get now, without locking anything (a preview).
     */
    public function quote(PredictMarket $market, string $side, int $stakeMinor): BetTerms;

    /**
     * Inside the caller's transaction: lock the engine's state, check the stake against it, run $placeWager, and
     * record the stake only when that placed a new wager (not a replay or a refusal).
     *
     * @param  Closure(): WagerResult  $placeWager
     * @return array{terms: BetTerms, wager: ?WagerResult}
     */
    public function placeBet(PredictMarket $market, string $side, int $stakeMinor, Closure $placeWager): array;

    /**
     * Who is paid what once the market resolved. Deterministic from the frozen state, so a rerun pays the same.
     */
    public function settle(PredictMarket $market, string $winningSide): SettlementPlan;
}
