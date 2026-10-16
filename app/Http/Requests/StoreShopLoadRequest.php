<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreShopLoadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Cash is whole shillings. The player number's check digit is verified by the service.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'player_no' => ['required', 'string', 'max:20'],
            'amount' => ['required', 'integer', 'min:'.config('bet_shops.loads.min_amount'), 'max:'.config('bet_shops.loads.max_amount')],
        ];
    }
}
