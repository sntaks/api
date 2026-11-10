<?php

namespace App\Http\Resources\Predict;

use App\Models\PredictMarket;
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
            'odds' => $this->relationLoaded('pool') && $this->pool ? self::odds($this->resource) : null,
            'opened_at' => $this->opened_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
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
            'yes_probability' => $yesShare / 10000,
            'no_probability' => (10000 - $yesShare) / 10000,
            'yes_multiplier' => PoolMath::multiplierX100($total, $pool->yes_minor, $market->cut_bp) / 100,
            'no_multiplier' => PoolMath::multiplierX100($total, $pool->no_minor, $market->cut_bp) / 100,
            'is_estimate' => true,
            'volume' => ($total - $pool->seed_yes_minor - $pool->seed_no_minor) / 100,
            'bets_count' => $pool->bets_count,
            'updated_at' => $pool->updated_at?->toIso8601String(),
        ];
    }
}
