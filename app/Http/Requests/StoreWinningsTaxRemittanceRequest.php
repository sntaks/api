<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWinningsTaxRemittanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'period_start' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start', 'before_or_equal:today'],
            'amount_paid' => ['required', 'numeric', 'gt:0', 'max:999999999999.99'],
            // A KRA payment reference may only be used once, unless the remittance using it was voided.
            'kra_reference' => [
                'required', 'string', 'max:100',
                Rule::unique('winnings_tax_remittances', 'kra_reference')->whereNull('voided_at'),
            ],
            'paid_at' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
        ];
    }
}
