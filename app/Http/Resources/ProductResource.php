<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'game' => $this->game->code,
            'name' => $this->name,
            'revenue_model' => $this->revenue_model,
            'status' => $this->status,
            'max_stake' => $this->max_stake === null ? null : (float) $this->max_stake,
            'max_payout' => $this->max_payout === null ? null : (float) $this->max_payout,
            'winnings_tax_rate' => (float) $this->winnings_tax_rate,
        ];
    }
}
