<?php

namespace App\Http\Resources;

use App\Models\WagerSettlement;
use Illuminate\Http\Request;

/**
 * A settlement as GMS sees it: the game's view plus who settled it and when it was reversed.
 *
 * @mixin WagerSettlement
 */
class WagerAdminSettlementResource extends WagerSettlementResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'admin_user' => $this->admin_user,
            'reversed_at' => $this->reversed_at?->toIso8601String(),
            'reversal_reason' => $this->reversal_reason,
        ];
    }
}
