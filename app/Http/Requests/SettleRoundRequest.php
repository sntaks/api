<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class SettleRoundRequest extends FormRequest
{
    /**
     * Winners a single call may settle. Larger rounds send their winners in non-final batches.
     */
    public const MAX_WINNERS = 1000;

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

        $rules = [
            'reference' => ['required', 'string', 'max:100'],
            'product' => ['required', 'string', 'max:50'],
            'winners' => ['bail', 'present', 'array', 'list', 'max:'.self::MAX_WINNERS],
            'final' => ['sometimes', 'boolean'],
        ];

        if ($this->isOverWinnerCap()) {
            return $rules;
        }

        return $rules + [
            'winners.*' => ['array'],
            'winners.*.reference' => ['required', 'string', 'max:100', 'distinct:ignore_case'],
            'winners.*.payout' => ['required', ...$money],
            'winners.*.bonus' => ['nullable', ...$money],
        ];
    }

    /**
     * Over the cap the whole list is refused, so its items are never checked one by one: `bail` only stops the
     * rules of `winners` itself, and `distinct` on each item compares it with every other (quadratic).
     */
    private function isOverWinnerCap(): bool
    {
        $winners = $this->input('winners');

        return is_array($winners) && count($winners) > self::MAX_WINNERS;
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
