<?php

namespace App\Http\Resources\Predict;

use App\Models\PredictMarket;
use Illuminate\Http\Request;

/**
 * A market as GMS sees it: the player view plus its terms, pool totals and audit fields.
 *
 * @mixin PredictMarket
 */
class PredictMarketAdminResource extends PredictMarketResource
{
    /**
     * A fixed-odds market's stakes and owed payouts per side, and the house result if each side wins (KES).
     *
     * @return array<string, mixed>
     */
    public static function exposure(PredictMarket $market): array
    {
        $rows = ($market->relationLoaded('exposures') ? $market->exposures : $market->exposures()->get())->keyBy('side');
        $stakes = (int) $rows->sum('stakes_minor');
        $line = [];

        foreach (PredictMarket::SIDES as $side) {
            $row = $rows[$side] ?? null;
            $line[$side] = [
                'stakes' => ($row?->stakes_minor ?? 0) / 100,
                'payouts_if_wins' => ($row?->payouts_minor ?? 0) / 100,
                'house_result_if_wins' => ($stakes - ($row?->payouts_minor ?? 0)) / 100,
            ];
        }

        return $line + ['worst_case' => min($line['yes']['house_result_if_wins'], $line['no']['house_result_if_wins'])];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'product' => $this->product?->code,
            'round_reference' => $this->round_reference,
            'cut' => $this->cut_bp / 10000,
            'seed' => $this->seed_minor / 100,
            'start_probability' => $this->start_probability_bp / 10000,
            'min_multiplier' => $this->min_multiplier_x100 / 100,
            'margin' => $this->margin_bp / 10000,
            'max_liability' => $this->max_liability_minor / 100,
            'max_customer_stake' => $this->max_customer_stake_minor / 100,
            'exposure' => $this->engine === PredictMarket::ENGINE_REFERENCE ? self::exposure($this->resource) : null,
            'pool' => $this->pool ? [
                'yes' => $this->pool->yes_minor / 100,
                'no' => $this->pool->no_minor / 100,
                'seed_yes' => $this->pool->seed_yes_minor / 100,
                'seed_no' => $this->pool->seed_no_minor / 100,
            ] : null,
            'suspension_cause' => $this->suspension_cause,
            'external' => $this->isImported() ? [
                'event_id' => $this->event?->external_event_id,
                'market_id' => $this->external_market_id,
                'condition_id' => $this->condition_id,
                'protocol_version' => $this->protocol_version,
                'slug' => $this->external_slug,
                'question' => $this->external_question,
                'end_at' => $this->external_end_at?->toIso8601String(),
                'game_start_at' => $this->external_game_start_at?->toIso8601String(),
                'closed' => $this->external_closed,
                'volume' => $this->external_volume !== null ? (float) $this->external_volume : null,
                'liquidity' => $this->external_liquidity !== null ? (float) $this->external_liquidity : null,
                'spread' => $this->external_spread !== null ? (float) $this->external_spread : null,
                'synced_at' => $this->external_synced_at?->toIso8601String(),
                'tokens' => $this->outcomes->mapWithKeys(fn ($outcome) => [$outcome->side => $outcome->external_token_id]),
            ] : null,
            'overridden_fields' => $this->overridden_fields ?? [],
            'rejected_at' => $this->rejected_at?->toIso8601String(),
            'reject_reason' => $this->reject_reason,
            'created_by' => $this->created_by,
            'resolved_by' => $this->resolved_by,
            'closed_at' => $this->closed_at?->toIso8601String(),
            'settled_at' => $this->settled_at?->toIso8601String(),
            'voided_at' => $this->voided_at?->toIso8601String(),
            'void_reason' => $this->void_reason,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
