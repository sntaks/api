<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AttributesBetShop;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreCompetitionTransactionRequest extends FormRequest
{
    use AttributesBetShop;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'competition_wallet_id' => 'required|exists:competition_wallets,id',
            'customer_id' => 'required|exists:customers,id',
            'payment_type' => 'required|string',
            'amount' => 'required|numeric',
            'bet_shop_no' => $this->betShopNoRules(),
        ];
    }
}
