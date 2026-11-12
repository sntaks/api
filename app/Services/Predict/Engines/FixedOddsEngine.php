<?php

namespace App\Services\Predict\Engines;

use App\Models\Customer;
use App\Models\PredictBet;
use App\Models\PredictExposure;
use App\Models\PredictMarket;
use App\Models\PredictOutcome;
use App\Models\PredictQuote;
use App\Models\Wager;
use App\Services\Predict\FixedOddsMath;
use App\Services\Predict\PoolMath;
use Closure;
use Illuminate\Support\Collection;

/**
 * Engine A, reference fixed odds: the house is the counterparty and the multiplier is locked when the bet is
 * placed. The fair price is Polymarket's midpoint for the side; we sell at p_fair × (1 + margin), capped at 0.99,
 * where the margin widens along `predict.fixed.margin_ladder` as the side's liability nears its cap,
 * and pay stake × floor(100 / p_offer) / 100, capped at the market's max payout.
 *
 * Every bet locks the market's two exposure rows, then its quote, so the exposure and per-customer limits are
 * checked against totals no other bet can change meanwhile.
 */
class FixedOddsEngine implements PricingEngine
{
    use ChecksQuotes;

    public function quote(PredictMarket $market, string $side, int $stakeMinor): BetTerms
    {
        $outcome = $market->outcomes()->where('side', $side)->first();

        return $this->priced($market, $outcome, PredictExposure::forMarket($market->id)->get()->keyBy('side'), $side);
    }

    public function placeBet(PredictMarket $market, string $side, int $stakeMinor, Closure $placeWager, ?PredictQuote $quote = null, ?Customer $customer = null): array
    {
        if ($quote === null || $customer === null) {
            return ['terms' => $this->quoteRequired(), 'wager' => null];
        }

        $exposures = PredictExposure::forMarket($market->id)->orderBy('side')->lockForUpdate()->get()->keyBy('side');
        $quote = PredictQuote::whereKey($quote->id)->lockForUpdate()->first();

        $refusal = $this->quoteRefusal($quote, $market, $customer, $side, $stakeMinor) ?? $this->limitRefusal($market, $exposures, $customer, $side, $stakeMinor, $quote);

        if ($refusal) {
            return ['terms' => $refusal, 'wager' => null];
        }

        $current = $this->priced($market, $market->outcomes()->where('side', $side)->first(), $exposures, $side);

        if ($current->isRefused()) {
            return ['terms' => $current, 'wager' => null];
        }

        if (FixedOddsMath::movedAgainstHouse((string) $quote->p_offer, $current->extra['p_offer'], (string) config('predict.fixed.requote_tolerance'))) {
            return ['terms' => BetTerms::refused('price_changed', 'The price moved since the quote; here is a new one', 409, [
                'multiplier' => $current->multiplierX100 / 100,
            ]), 'wager' => null];
        }

        // A move in the player's favour (or within tolerance) keeps the quoted multiplier
        $terms = BetTerms::fixed($quote->multiplier_x100, (string) $quote->p_fair, (string) $quote->p_offer);
        $wager = $placeWager();

        if (! $wager->isRefused() && ! $wager->replayed) {
            $payout = PoolMath::payoutMinor($stakeMinor, $quote->multiplier_x100, $market->max_payout_minor);

            PredictExposure::forMarket($market->id)->where('side', $side)->update([
                'stakes_minor' => $exposures[$side]->stakes_minor + $stakeMinor,
                'payouts_minor' => $exposures[$side]->payouts_minor + $payout,
                'updated_at' => now(),
            ]);
        }

        return ['terms' => $terms, 'wager' => $wager];
    }

    public function settle(PredictMarket $market, string $winningSide): SettlementPlan
    {
        $bets = PredictBet::where('predict_market_id', $market->id)
            ->where('status', '!=', PredictBet::STATUS_VOID)
            ->whereHas('wager', fn ($wager) => $wager->where('status', '!=', Wager::STATUS_VOID))
            ->get(['id', 'reference', 'side', 'stake_minor', 'estimated_multiplier_x100']);

        $winners = [];
        $payout = 0;

        foreach ($bets->where('side', $winningSide) as $bet) {
            $amount = PoolMath::payoutMinor($bet->stake_minor, $bet->estimated_multiplier_x100, $market->max_payout_minor);
            $winners[$bet->id] = ['reference' => $bet->reference, 'payout_minor' => $amount];
            $payout += $amount;
        }

        // No single final multiplier: each bet keeps the one it was placed at
        return new SettlementPlan($winningSide, 0, $winners, (int) $bets->sum('stake_minor'), $payout);
    }

    /**
     * The offer for a side now, or why it cannot be bet on (a betting guard failed, or the price is too high to
     * pay more than the stake back).
     */
    /**
     * @param  Collection<string, PredictExposure>  $exposures
     */
    private function priced(PredictMarket $market, ?PredictOutcome $outcome, $exposures, string $side): BetTerms
    {
        // Not `?->… ?? 'no_price'`: a passing guard returns null, which ?? would turn into a failure
        $failure = $outcome ? $outcome->bettingGuardFailure() : 'no_price';

        if ($failure !== null) {
            return BetTerms::refused('price_unavailable', 'This market cannot be priced right now', 409, ['reason' => $failure]);
        }

        $fair = bcadd((string) $outcome->midpoint, '0', 6);
        $offer = FixedOddsMath::offerPrice($fair, self::marginBp($market, $exposures, $side), (string) config('predict.fixed.max_offer'));
        $multiplier = FixedOddsMath::multiplierX100($offer);

        if ($multiplier <= 100) {
            return BetTerms::refused('odds_unavailable', 'This side is too likely to offer a return', 409);
        }

        return BetTerms::fixed($multiplier, $fair, $offer);
    }

    /**
     * The market's margin for a side, widened by the ladder as the side's liability nears its cap.
     *
     * @param  Collection<string, PredictExposure>  $exposures
     */
    public static function marginBp(PredictMarket $market, $exposures, string $side): int
    {
        $liability = (int) ($exposures[$side]->payouts_minor ?? 0) - (int) $exposures->sum('stakes_minor');

        return FixedOddsMath::ladderMarginBp($market->margin_bp, $liability, $market->max_liability_minor, config('predict.fixed.margin_ladder', []));
    }

    /**
     * The house's exposure cap on the side, and the customer's total-stake cap on the market.
     *
     * @param  Collection<string, PredictExposure>  $exposures
     */
    private function limitRefusal(PredictMarket $market, $exposures, Customer $customer, string $side, int $stakeMinor, PredictQuote $quote): ?BetTerms
    {
        $payout = PoolMath::payoutMinor($stakeMinor, $quote->multiplier_x100, $market->max_payout_minor);
        $allStakes = (int) $exposures->sum('stakes_minor');
        $liability = $exposures[$side]->payouts_minor + $payout - ($allStakes + $stakeMinor);

        if ($liability > $market->max_liability_minor) {
            return BetTerms::refused('exposure_limit', 'This side cannot take more bets right now', 409);
        }

        $staked = (int) PredictBet::where('predict_market_id', $market->id)
            ->where('customer_id', $customer->id)
            ->where('status', '!=', PredictBet::STATUS_VOID)
            ->sum('stake_minor');

        if ($staked + $stakeMinor > $market->max_customer_stake_minor) {
            return BetTerms::refused('customer_limit', 'This stake is above what the player may still stake on this market', 422, [
                'remaining' => max(0, $market->max_customer_stake_minor - $staked) / 100,
            ]);
        }

        return null;
    }
}
