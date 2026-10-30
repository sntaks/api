<?php

namespace App\Http\Resources;

use App\Models\Wager;
use Illuminate\Http\Request;

/**
 * A wager as GMS sees it: the history view plus its id, the player and whether it is a tombstone.
 *
 * @mixin Wager
 */
class WagerAdminResource extends WagerHistoryResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'customer_id' => $this->customer_id] + parent::toArray($request) + ['is_tombstone' => (bool) $this->is_tombstone];
    }
}
