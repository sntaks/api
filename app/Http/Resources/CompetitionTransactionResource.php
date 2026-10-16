<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompetitionTransactionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'competition_wallet_id' => $this->competition_wallet_id,
            'customer_id' => $this->customer_id,
            'payment_type' => $this->payment_type,
            'amount' => $this->amount,
            'status' => $this->status,
            'bet_shop_id' => $this->bet_shop_id,
            'created_at' => $this->created_at,
        ];
    }
}
