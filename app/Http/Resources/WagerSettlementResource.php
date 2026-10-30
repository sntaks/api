<?php

namespace App\Http\Resources;

use App\Models\WagerSettlement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One version of a wager's outcome as a game sees it.
 *
 * @mixin WagerSettlement
 */
class WagerSettlementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'version' => (int) $this->version,
            'outcome' => $this->outcome,
            'payout' => (float) $this->payout,
            'bonus' => (float) $this->bonus,
            'tax' => (float) $this->tax,
            'tax_base' => (float) $this->tax_base,
            'tax_rate' => (float) $this->tax_rate,
            'reason' => $this->reason,
            'settled_by' => $this->settled_by,
            'status' => $this->status,
            'settled_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
