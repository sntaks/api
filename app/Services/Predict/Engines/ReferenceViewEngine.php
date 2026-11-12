<?php

namespace App\Services\Predict\Engines;

use App\Models\PredictMarket;
use Closure;

/**
 * Imported markets before fixed odds exist (phase 1b): players see the reference prices but cannot bet, so
 * nothing is ever paid. Phase 2 maps the reference engine to the fixed-odds engine instead.
 */
class ReferenceViewEngine implements PricingEngine
{
    public function quote(PredictMarket $market, string $side, int $stakeMinor): BetTerms
    {
        return $this->viewOnly();
    }

    public function placeBet(PredictMarket $market, string $side, int $stakeMinor, Closure $placeWager): array
    {
        return ['terms' => $this->viewOnly(), 'wager' => null];
    }

    public function settle(PredictMarket $market, string $winningSide): SettlementPlan
    {
        return new SettlementPlan($winningSide, 0, [], 0, 0);
    }

    private function viewOnly(): BetTerms
    {
        return BetTerms::refused('betting_not_available', 'This market shows reference prices only; it does not take bets yet', 409);
    }
}
