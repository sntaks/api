<?php

namespace App\Http\Resources;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The player as a game sees them: no phone, email, national ID or full name ever leaves the API.
 *
 * @mixin Customer
 */
class PlayerResource extends JsonResource
{
    /**
     * @return array{id: int, player_no: string|null, account_no: string|null, display_name: string, status: int}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'player_no' => $this->player_no,
            'account_no' => $this->account_no,
            'display_name' => $this->displayName(),
            'status' => (int) $this->status,
        ];
    }
}
