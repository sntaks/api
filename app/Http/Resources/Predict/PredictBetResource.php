<?php

namespace App\Http\Resources\Predict;

use App\Models\PredictBet;
use App\Models\PredictMarket;
use App\Services\Predict\PoolMath;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A player's bet. While open, the multiplier and payout are the pool's current estimate; once settled they are
 * final.
 *
 * @mixin PredictBet
 */
class PredictBetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $market = $this->market;
        $isOpen = $this->status === PredictBet::STATUS_OPEN;
        // A pool bet's multiplier moves until the market closes; fixed-odds and LMSR bets keep the one they got
        $isPool = $market?->engine === PredictMarket::ENGINE_POOL;
        $multiplier = $isOpen ? ($isPool ? $this->currentMultiplierX100() : $this->estimated_multiplier_x100) : $this->final_multiplier_x100;

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'market_id' => $this->predict_market_id,
            'question' => $market?->question,
            'market_status' => $market?->status,
            'side' => $this->side,
            'currency' => $this->currency,
            'stake' => $this->stake_minor / 100,
            'status' => $this->status,
            'multiplier' => $multiplier !== null ? $multiplier / 100 : null,
            'multiplier_at_placement' => $this->estimated_multiplier_x100 / 100,
            'potential_payout' => $isOpen && $multiplier !== null && $market
                ? ($this->shares_minor ?? PoolMath::payoutMinor($this->stake_minor, $multiplier, $market->max_payout_minor)) / 100
                : null,
            'payout' => $this->payout_minor !== null ? $this->payout_minor / 100 : null,
            'is_estimate' => $isOpen && $isPool,
            'placed_at' => $this->placed_at?->toIso8601String(),
            'settled_at' => $this->settled_at?->toIso8601String(),
        ];
    }

    private function currentMultiplierX100(): ?int
    {
        $pool = $this->market?->pool;

        if (! $pool) {
            return null;
        }

        $side = $this->side === 'yes' ? $pool->yes_minor : $pool->no_minor;

        return PoolMath::multiplierX100($pool->yes_minor + $pool->no_minor, $side, $this->market->cut_bp);
    }
}
