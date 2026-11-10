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
            'pool' => $this->pool ? [
                'yes' => $this->pool->yes_minor / 100,
                'no' => $this->pool->no_minor / 100,
                'seed_yes' => $this->pool->seed_yes_minor / 100,
                'seed_no' => $this->pool->seed_no_minor / 100,
            ] : null,
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
