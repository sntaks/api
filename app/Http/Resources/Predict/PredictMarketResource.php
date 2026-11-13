<?php

namespace App\Http\Resources\Predict;

use App\Models\PredictLmsrState;
use App\Models\PredictMarket;
use App\Models\PredictOutcome;
use App\Services\Predict\Engines\FixedOddsEngine;
use App\Services\Predict\FixedOddsMath;
use App\Services\Predict\LmsrMath;
use App\Services\Predict\PoolMath;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A market as players see it: the question, its rules and the live pool odds. Times are ISO 8601 with offset.
 *
 * @mixin PredictMarket
 */
class PredictMarketResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $country = $this->countries[0] ?? config('predict.default_country');

        return [
            'id' => $this->id,
            'source' => $this->source,
            'question' => $this->question,
            'description' => $this->description,
            'resolution_criteria' => $this->resolution_criteria,
            'resolution_source' => $this->resolution_source,
            'category' => $this->category,
            'image_url' => $this->image_url,
            'external_url' => $this->externalUrl(),
            'countries' => $this->countries,
            'timezone' => config("predict.countries.{$country}.timezone"),
            'currency' => config('predict.currency'),
            'status' => $this->status,
            'engine' => $this->engine,
            'close_at' => $this->close_at?->toIso8601String(),
            'resolution_deadline_at' => $this->resolution_deadline_at?->toIso8601String(),
            'result' => $this->result,
            'min_stake' => $this->min_stake_minor / 100,
            'max_stake' => $this->max_stake_minor / 100,
            'max_payout' => $this->max_payout_minor / 100,
            'event' => $this->relationLoaded('event') && $this->event ? [
                'id' => $this->event->id,
                'title' => $this->event->title,
            ] : null,
            'betting_available' => $this->takesBets(),
            'betting_closes_at' => $this->bettingClosesAt()->toIso8601String(),
            'odds' => $this->oddsFor($this->resource),
            'opened_at' => $this->opened_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
        ];
    }

    /**
     * The market's page on polymarket.com, from the slugs imported with it; null for our own markets.
     */
    private function externalUrl(): ?string
    {
        $eventSlug = $this->isImported() && $this->relationLoaded('event') ? $this->event?->slug : null;

        if (! $eventSlug) {
            return null;
        }

        return 'https://polymarket.com/event/'.rawurlencode($eventSlug).($this->external_slug ? '/'.rawurlencode($this->external_slug) : '');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function oddsFor(PredictMarket $market): ?array
    {
        if ($market->isImported()) {
            return $market->relationLoaded('outcomes') ? self::referenceOdds($market) : null;
        }

        if ($market->engine === PredictMarket::ENGINE_LMSR) {
            $state = $market->relationLoaded('lmsrState') ? $market->lmsrState : $market->lmsrState()->first();

            return $state ? self::lmsrOdds($market, $state) : null;
        }

        return $market->relationLoaded('pool') && $market->pool ? self::odds($market) : null;
    }

    /**
     * An imported market's reference prices (Polymarket midpoints), with the outcome labels and attribution, and
     * the fixed-odds multipliers we would offer now (indicative: a bet needs a quote). Null when a side cannot be
     * priced.
     *
     * @return array<string, mixed>
     */
    public static function referenceOdds(PredictMarket $market): array
    {
        $yes = $market->outcomes->firstWhere('side', PredictMarket::SIDE_YES);
        $no = $market->outcomes->firstWhere('side', PredictMarket::SIDE_NO);
        $price = fn ($outcome) => $outcome?->hasUsablePrice() ? (float) $outcome->midpoint : null;

        return [
            'yes_label' => $yes?->label,
            'no_label' => $no?->label,
            'yes_probability' => $price($yes),
            'no_probability' => $price($no),
            'yes_multiplier' => self::offerMultiplier($market, $yes),
            'no_multiplier' => self::offerMultiplier($market, $no),
            'is_estimate' => true,
            'is_reference' => true,
            'attribution' => config('predict.polymarket.attribution'),
            'updated_at' => $yes?->price_captured_at?->toIso8601String(),
        ];
    }

    /**
     * The multiplier a quote would offer now, or null when the side fails a betting guard.
     */
    private static function offerMultiplier(PredictMarket $market, ?PredictOutcome $outcome): ?float
    {
        if (! $outcome || $outcome->bettingGuardFailure() !== null) {
            return null;
        }

        $exposures = ($market->relationLoaded('exposures') ? $market->exposures : $market->exposures()->get())->keyBy('side');
        $margin = FixedOddsEngine::marginBp($market, $exposures, $outcome->side);
        $offer = FixedOddsMath::offerPrice(bcadd((string) $outcome->midpoint, '0', 6), $margin, (string) config('predict.fixed.max_offer'));
        $multiplier = FixedOddsMath::multiplierX100($offer);

        return $multiplier > 100 ? $multiplier / 100 : null;
    }

    /**
     * An LMSR market's current prices, and the multiplier the next KES 1 would get after the fee (indicative:
     * larger stakes move the price, and a bet needs a quote).
     *
     * @return array<string, mixed>
     */
    public static function lmsrOdds(PredictMarket $market, PredictLmsrState $state): array
    {
        $liquidity = (string) $state->liquidity;
        $yes = LmsrMath::price((string) $state->q_yes, (string) $state->q_no, $liquidity);
        $no = LmsrMath::price((string) $state->q_no, (string) $state->q_yes, $liquidity);
        $keep = bcdiv((string) (10000 - $market->cut_bp), '10000', 4);
        $multiplier = fn (string $price) => bccomp($price, '0', 6) > 0 ? (int) bcdiv(bcmul($keep, '100', 4), $price, 0) / 100 : null;

        return [
            'yes_label' => 'Yes',
            'no_label' => 'No',
            'yes_probability' => (float) $yes,
            'no_probability' => (float) $no,
            'yes_multiplier' => $multiplier($yes),
            'no_multiplier' => $multiplier($no),
            'is_estimate' => true,
            'is_reference' => false,
            'updated_at' => $state->updated_at?->toIso8601String(),
        ];
    }

    /**
     * The pool's current shares and multipliers (the seed included) and the players' volume (the seed excluded).
     *
     * @return array<string, mixed>
     */
    public static function odds(PredictMarket $market): array
    {
        $pool = $market->pool;
        $total = $pool->yes_minor + $pool->no_minor;
        $yesShare = PoolMath::shareBp($total, $pool->yes_minor);

        return [
            'yes_label' => 'Yes',
            'no_label' => 'No',
            'yes_probability' => $yesShare / 10000,
            'no_probability' => (10000 - $yesShare) / 10000,
            'yes_multiplier' => PoolMath::multiplierX100($total, $pool->yes_minor, $market->cut_bp) / 100,
            'no_multiplier' => PoolMath::multiplierX100($total, $pool->no_minor, $market->cut_bp) / 100,
            'is_estimate' => true,
            'is_reference' => false,
            'volume' => ($total - $pool->seed_yes_minor - $pool->seed_no_minor) / 100,
            'bets_count' => $pool->bets_count,
            'updated_at' => $pool->updated_at?->toIso8601String(),
        ];
    }
}
