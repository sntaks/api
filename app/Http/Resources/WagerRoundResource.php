<?php

namespace App\Http\Resources;

use App\Models\WagerRound;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A round as a game sees it.
 *
 * @mixin WagerRound
 */
class WagerRoundResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'reference' => $this->reference,
            'product' => $this->product->code,
            'status' => $this->status,
            'closed_at' => $this->closed_at?->toIso8601String(),
        ];
    }
}
