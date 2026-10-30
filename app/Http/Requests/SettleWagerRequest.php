<?php

namespace App\Http\Requests;

use App\Models\WagerSettlement;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class SettleWagerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['reference' => (string) $this->route('reference')]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        // wallets.balance is decimal(10,2): a larger amount could never be posted (an overflow is a retryable 500)
        $money = ['numeric', 'min:0', 'decimal:0,2', 'max:99999999.99'];

        return [
            'reference' => ['required', 'string', 'max:100'],
            'product' => ['required', 'string', 'max:50'],
            'version' => ['required', 'integer', 'min:1', 'max:4294967295'],
            'outcome' => ['required', 'in:'.implode(',', WagerSettlement::OUTCOMES)],
            'payout' => ['required', ...$money],
            'bonus' => ['nullable', ...$money],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => $validator->errors()->first(),
            'code' => 'validation_failed',
            'retryable' => false,
            'errors' => $validator->errors(),
        ], 422));
    }
}
