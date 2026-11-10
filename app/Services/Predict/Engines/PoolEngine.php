<?php

namespace App\Services\Predict\Engines;

use App\Models\PredictBet;
use App\Models\PredictMarket;
use App\Models\PredictPool;
use App\Models\Wager;
use App\Services\Predict\PoolMath;
use Closure;

/**
 * Engine B, pari-mutuel: every stake joins one pot, the house keeps the cut, and the winning side shares the
 * rest in proportion to stake. The house seed opens the odds and counts as a winning stake, but it is notional:
 * no money moves for it, so the house's result on a market is simply user stakes minus payouts.
 */
class PoolEngine implements PricingEngine
{
    public function quote(PredictMarket $market, string $side, int $stakeMinor): BetTerms
    {
        $pool = $market->pool()->firstOrFail();

        return $this->terms($market, $pool, $side, $stakeMinor);
    }

    public function placeBet(PredictMarket $market, string $side, int $stakeMinor, Closure $placeWager): array
    {
        $pool = PredictPool::whereKey($market->id)->lockForUpdate()->firstOrFail();
        $terms = $this->terms($market, $pool, $side, $stakeMinor);

        if ($terms->isRefused()) {
            return ['terms' => $terms, 'wager' => null];
        }

        $wager = $placeWager();

        if (! $wager->isRefused() && ! $wager->replayed) {
            $column = $side === PredictMarket::SIDE_YES ? 'yes_minor' : 'no_minor';
            $pool->{$column} += $stakeMinor;
            $pool->bets_count++;
            $pool->save();
        }

        return ['terms' => $terms, 'wager' => $wager];
    }

    public function settle(PredictMarket $market, string $winningSide): SettlementPlan
    {
        $pool = $market->pool()->firstOrFail();
        $total = $pool->yes_minor + $pool->no_minor;
        $winningTotal = $winningSide === PredictMarket::SIDE_YES ? $pool->yes_minor : $pool->no_minor;
        $multiplier = PoolMath::multiplierX100($total, $winningTotal, $market->cut_bp);

        // A bet whose wager GMS voided on its own was refunded: it is not paid, and listing it would make the
        // round refuse the whole settlement
        $bets = PredictBet::where('predict_market_id', $market->id)
            ->where('status', '!=', PredictBet::STATUS_VOID)
            ->whereHas('wager', fn ($wager) => $wager->where('status', '!=', Wager::STATUS_VOID))
            ->get(['id', 'reference', 'side', 'stake_minor']);

        $winners = [];
        $payout = 0;

        foreach ($bets->where('side', $winningSide) as $bet) {
            $amount = PoolMath::payoutMinor($bet->stake_minor, $multiplier, $market->max_payout_minor);
            $winners[$bet->id] = ['reference' => $bet->reference, 'payout_minor' => $amount];
            $payout += $amount;
        }

        return new SettlementPlan($winningSide, $multiplier, $winners, (int) $bets->sum('stake_minor'), $payout);
    }

    /**
     * The estimated multiplier for the stake, or `pool_side_full` with the largest stake still allowed when it
     * would push the side below the market's minimum multiplier.
     */
    private function terms(PredictMarket $market, PredictPool $pool, string $side, int $stakeMinor): BetTerms
    {
        $total = $pool->yes_minor + $pool->no_minor;
        $sideTotal = $side === PredictMarket::SIDE_YES ? $pool->yes_minor : $pool->no_minor;
        $estimate = PoolMath::estimateX100($total, $sideTotal, $stakeMinor, $market->cut_bp);

        if ($estimate < $market->min_multiplier_x100) {
            $largest = PoolMath::maxStakeForMinimum($total, $sideTotal, $market->cut_bp, $market->min_multiplier_x100);

            return BetTerms::refused('pool_side_full', 'This side cannot take a stake that large now', 422, [
                'max_stake' => $largest >= $market->min_stake_minor ? $largest / 100 : 0,
            ]);
        }

        return BetTerms::estimate($estimate);
    }
}
