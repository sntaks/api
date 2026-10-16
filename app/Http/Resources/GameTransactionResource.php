<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GameTransactionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $gameTransactionArr = [
            'id' => $this->id,
            'payment_type' => $this->payment_type,
            'amount' => $this->amount,
            'status' => $this->status,
            'bet_shop_id' => $this->bet_shop_id,
            'bet_shop_no' => $this->bet_shop_id ? $this->betShop?->shop_no : null,
        ];
        $gameWallet = $this->gameWallet;
        if ($gameWallet) {
            $gameTransactionArr = array_merge($gameTransactionArr, [
                'game_wallet_id' => $gameWallet->id,
                'balance' => $gameWallet->balance,
            ]);
        }
        $customer = $this->customer;
        if ($customer) {
            $gameTransactionArr = array_merge($gameTransactionArr, [
                'customer_id' => $customer->id,
                'account_no' => $customer->account_no,
                'player_no' => $customer->player_no,
                'customer_name' => $customer->name,
            ]);
        }

        return $gameTransactionArr;
    }
}
