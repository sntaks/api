<?php

namespace App\Http\Resources;

use App\Models\Wager;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A wager as a game sees it.
 *
 * @mixin Wager
 */
class WagerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'reference' => $this->reference,
            'product' => $this->product->code,
            'funding' => $this->funding,
            'stake' => (float) $this->stake,
            'free_bet_amount' => (float) $this->free_bet_amount,
            'status' => $this->status,
            'settlement_version' => (int) $this->settlement_version,
            'round_reference' => $this->round?->reference,
            'placed_at' => $this->placed_at?->toIso8601String(),
        ];
    }
}
