<?php

namespace App\Http\Resources\Predict;

use App\Models\PredictQuote;
use App\Services\Predict\PoolMath;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A fixed-odds quote: send `quote_id` with the bet before `expires_at`.
 *
 * @mixin PredictQuote
 */
class PredictQuoteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $market = $this->market;

        return [
            'quote_id' => $this->id,
            'market_id' => $this->predict_market_id,
            'side' => $this->side,
            'stake' => $this->stake_minor / 100,
            'multiplier' => $this->multiplier_x100 / 100,
            // LMSR locks the payout (the shares bought); fixed odds derive it from the multiplier
            'potential_payout' => ($this->payout_minor ?? PoolMath::payoutMinor($this->stake_minor, $this->multiplier_x100, $market->max_payout_minor)) / 100,
            'price' => (float) $this->p_offer,
            'expires_at' => $this->expires_at->toIso8601String(),
        ];
    }
}
