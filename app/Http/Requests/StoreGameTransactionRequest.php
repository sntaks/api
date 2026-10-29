<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AttributesBetShop;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreGameTransactionRequest extends FormRequest
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
     * Get the validation rules that apply to the request. A new stake must name its customer; an edit
     * (PUT, which shares this request) may leave the customer out.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'game_wallet_id' => 'required|integer',
            'customer_id' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'integer', 'exists:customers,id'],
            'payment_type' => 'required|string',
            'amount' => 'required|numeric|gt:0|decimal:0,2',
            'status' => 'integer',
            'bet_shop_no' => $this->betShopNoRules(),
        ];
    }
}
