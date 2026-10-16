<?php

namespace App\Http\Resources;

use App\Models\DepositResolution;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DepositResolutionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'action' => $this->action,
            'customer_id' => $this->customer_id,
            'customer_name' => $this->customer?->name,
            'account_no_used' => $this->account_no_used,
            'ledger_entry_id' => $this->ledgerEntry?->entry_id,
            'mpesa_reference' => $this->mpesa_reference,
            'note' => $this->note,
            'resolved_by' => $this->resolved_by,
            'resolved_at' => $this->created_at?->toIso8601String(),
        ] + ($this->action === DepositResolution::ACTION_SHOP_FLOAT ? $this->shopFloat() : []);
    }

    /**
     * The shop a `shop_float` resolution credited, and the float purchase to void if it was the wrong one.
     *
     * @return array{float_purchase_id: ?int, bet_shop_id: ?int, shop_no: ?string, shop_name: ?string}
     */
    private function shopFloat(): array
    {
        $purchase = $this->shopFloatPurchase;

        return [
            'float_purchase_id' => $purchase?->id,
            'bet_shop_id' => $purchase?->bet_shop_id,
            'shop_no' => $purchase?->betShop?->shop_no,
            'shop_name' => $purchase?->betShop?->name,
        ];
    }
}
