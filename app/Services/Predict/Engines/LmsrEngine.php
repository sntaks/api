<?php

namespace App\Services\Predict\Engines;

use App\Models\Customer;
use App\Models\PredictBet;
use App\Models\PredictExposure;
use App\Models\PredictLmsrState;
use App\Models\PredictMarket;
use App\Models\PredictQuote;
use App\Models\Wager;
use App\Services\Predict\LmsrMath;
use Closure;

/**
 * Engine C, LMSR market maker, for custom markets: the house sells shares (each pays KES 1 if its side wins)
 * along the LMSR cost curve, so every bet moves the price and the house's loss is bounded by
 * b·ln(1 / opening price). A fee (the market's cut) is taken from each stake before it buys shares. The payout
 * is locked through a quote, as for fixed odds; a quote is honoured unless the house would now pay more than
 * `predict.fixed.requote_tolerance` more than the same stake would buy.
 *
 * Lock order: market (shared) → LMSR state → quote → the side's exposure row → round → wager → wallet.
 */
class LmsrEngine implements PricingEngine
{
    use ChecksQuotes;

    public function quote(PredictMarket $market, string $side, int $stakeMinor): BetTerms
    {
        $state = PredictLmsrState::whereKey($market->id)->firstOrFail();

        return $this->priced($market, $state, $side, $stakeMinor);
    }

    public function placeBet(PredictMarket $market, string $side, int $stakeMinor, Closure $placeWager, ?PredictQuote $quote = null, ?Customer $customer = null): array
    {
        if ($quote === null || $customer === null) {
            return ['terms' => $this->quoteRequired(), 'wager' => null];
        }

        $state = PredictLmsrState::whereKey($market->id)->lockForUpdate()->firstOrFail();
        $quote = PredictQuote::whereKey($quote->id)->lockForUpdate()->first();
        $exposure = PredictExposure::forMarket($market->id)->where('side', $side)->lockForUpdate()->first();
        $refusal = $this->quoteRefusal($quote, $market, $customer, $side, $stakeMinor);

        if ($refusal) {
            return ['terms' => $refusal, 'wager' => null];
        }

        $current = $this->priced($market, $state, $side, $stakeMinor);

        if ($current->isRefused()) {
            return ['terms' => $current, 'wager' => null];
        }

        // The quote costs the house too much now: it would pay more than the tolerance above what the stake buys
        $limit = bcmul((string) $current->extra['payout_minor'], bcadd('1', (string) config('predict.fixed.requote_tolerance'), 6), 0);

        if (bccomp((string) $quote->payout_minor, $limit, 0) > 0) {
            return ['terms' => BetTerms::refused('price_changed', 'The price moved since the quote; here is a new one', 409, [
                'multiplier' => $current->multiplierX100 / 100,
            ]), 'wager' => null];
        }

        $terms = BetTerms::fixed($quote->multiplier_x100, (string) $quote->p_fair, (string) $quote->p_offer, $quote->payout_minor);
        $wager = $placeWager();

        if (! $wager->isRefused() && ! $wager->replayed) {
            $column = $side === PredictMarket::SIDE_YES ? 'q_yes' : 'q_no';
            $state->{$column} = bcadd((string) $state->{$column}, bcdiv((string) $quote->payout_minor, '100', 6), 6);
            $state->save();

            PredictExposure::forMarket($market->id)->where('side', $side)->update([
                'stakes_minor' => $exposure->stakes_minor + $stakeMinor,
                'payouts_minor' => $exposure->payouts_minor + $quote->payout_minor,
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
            ->get(['id', 'reference', 'side', 'stake_minor', 'shares_minor']);

        $winners = [];
        $payout = 0;

        foreach ($bets->where('side', $winningSide) as $bet) {
            $winners[$bet->id] = ['reference' => $bet->reference, 'payout_minor' => (int) $bet->shares_minor];
            $payout += (int) $bet->shares_minor;
        }

        return new SettlementPlan($winningSide, 0, $winners, (int) $bets->sum('stake_minor'), $payout);
    }

    /**
     * What the stake buys now: its shares (the payout, in minor units, capped at the market's max payout), the
     * side's current price and the average price paid.
     */
    private function priced(PredictMarket $market, PredictLmsrState $state, string $side, int $stakeMinor): BetTerms
    {
        [$qSide, $qOther] = $side === PredictMarket::SIDE_YES
            ? [(string) $state->q_yes, (string) $state->q_no]
            : [(string) $state->q_no, (string) $state->q_yes];

        $netSpend = bcdiv(bcmul((string) $stakeMinor, (string) (10000 - $market->cut_bp)), '1000000', 6);
        $shares = LmsrMath::sharesFor($netSpend, $qSide, $qOther, (string) $state->liquidity);
        $payoutMinor = min((int) bcmul($shares, '100', 0), $market->max_payout_minor);

        if ($payoutMinor <= $stakeMinor) {
            return BetTerms::refused('odds_unavailable', 'This side is too likely to offer a return', 409);
        }

        return BetTerms::fixed(
            (int) bcdiv(bcmul((string) $payoutMinor, '100'), (string) $stakeMinor, 0),
            LmsrMath::price($qSide, $qOther, (string) $state->liquidity),
            bcdiv((string) $stakeMinor, (string) $payoutMinor, 6),
            $payoutMinor,
        );
    }
}
