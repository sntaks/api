<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The code, game and revenue model are fixed: ledger entries and finance reports refer to them.
     * A null limit means no limit.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'status' => ['sometimes', 'required', 'in:'.implode(',', Product::STATUSES)],
            'max_stake' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'decimal:0,2'],
            'max_payout' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'decimal:0,2'],
            'winnings_tax_rate' => ['sometimes', 'required', 'numeric', 'between:0,1', 'decimal:0,4'],
        ];
    }
}
