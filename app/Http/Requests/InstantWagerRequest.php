<?php

namespace App\Http\Requests;

use App\Models\WagerSettlement;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Validator;

/**
 * A bet and its result in one call: the place fields plus a won or lost outcome. A void is never instant.
 */
class InstantWagerRequest extends PlaceWagerRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // wallets.balance is decimal(10,2): a larger amount could never be posted (an overflow is a retryable 500)
        $money = ['numeric', 'min:0', 'decimal:0,2', 'max:99999999.99'];

        return parent::rules() + [
            'outcome' => ['required', 'in:'.WagerSettlement::OUTCOME_WON.','.WagerSettlement::OUTCOME_LOST],
            'payout' => ['required', ...$money],
            'bonus' => ['nullable', ...$money],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [...parent::after(), function (Validator $validator): void {
            $paysSomething = (float) $this->input('payout', 0) > 0 || (float) $this->input('bonus', 0) > 0;

            if ($this->input('outcome') === WagerSettlement::OUTCOME_LOST && $paysSomething) {
                $validator->errors()->add('payout', 'A lost bet must have a payout and bonus of 0.');
            }
        }];
    }
}
