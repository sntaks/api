<?php

namespace App\Http\Resources;

use App\Models\Wager;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A wager in a player's history: the game's view plus its game, outcome amounts and settlement time. Needs
 * `product.game` and `round` loaded.
 *
 * @mixin Wager
 */
class WagerHistoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'reference' => $this->reference,
            'game' => $this->product->game->code,
            'product' => $this->product->code,
            'funding' => $this->funding,
            'stake' => (float) $this->stake,
            'free_bet_amount' => (float) $this->free_bet_amount,
            'status' => $this->status,
            'settlement_version' => (int) $this->settlement_version,
            'payout' => (float) $this->payout,
            'bonus' => (float) $this->bonus,
            'tax' => (float) $this->tax,
            'round_reference' => $this->round?->reference,
            'placed_at' => $this->placed_at?->toIso8601String(),
            'settled_at' => $this->settled_at?->toIso8601String(),
        ];
    }
}
