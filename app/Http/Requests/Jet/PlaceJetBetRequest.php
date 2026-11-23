<?php

namespace App\Http\Requests\Jet;

use Illuminate\Foundation\Http\FormRequest;

/**
 * M1 place body: money and multipliers are canonical two-place decimal strings ("100.00"), never JSON numbers.
 * auto_cashout is optional (missing and null mean the same) and at least 1.01.
 */
class PlaceJetBetRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'round_number' => ['required', 'integer', 'min:1'],
            'stake' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,9})\.[0-9]{2}$/D'],
            'auto_cashout' => ['nullable', 'string', 'regex:/^[1-9][0-9]{0,7}\.[0-9]{2}$/D', function (string $attribute, string $value, \Closure $fail) {
                if (bccomp($value, '1.01', 2) < 0) {
                    $fail('The auto cash-out must be at least 1.01.');
                }
            }],
        ];
    }
}
