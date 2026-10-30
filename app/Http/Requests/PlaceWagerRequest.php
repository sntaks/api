<?php

namespace App\Http\Requests;

use App\Models\Wager;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class PlaceWagerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $isFreeBet = $this->input('funding') === Wager::FUNDING_FREE_BET;
        // wallets.balance is decimal(10,2): a larger amount could never be posted (an overflow is a retryable 500)
        $money = ['numeric', 'gt:0', 'decimal:0,2', 'max:99999999.99'];

        return [
            'product' => ['required', 'string', 'max:50'],
            'reference' => ['required', 'string', 'max:100'],
            'round_reference' => ['nullable', 'string', 'max:100'],
            'funding' => ['required', 'in:'.implode(',', Wager::FUNDINGS)],
            'stake' => $isFreeBet ? ['nullable', 'in:0'] : ['required', ...$money],
            'free_bet_amount' => $isFreeBet ? ['required', ...$money] : ['prohibited'],
            'channel' => ['nullable', 'string', 'max:20'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (strlen((string) json_encode($this->input('metadata'))) > 4096) {
                $validator->errors()->add('metadata', 'The metadata may be at most 4 KB of JSON.');
            }
        }];
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
