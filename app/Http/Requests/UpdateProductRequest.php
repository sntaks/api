<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

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
            'min_stake' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'decimal:0,2'],
            'max_stake' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'decimal:0,2'],
            'max_payout' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'decimal:0,2'],
            'winnings_tax_rate' => ['sometimes', 'required', 'numeric', 'between:0,1', 'decimal:0,4'],
        ];
    }

    /**
     * The minimum stake may not be above the maximum, comparing what is sent with what is stored.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $product = Product::where('code', $this->route('code'))->first();
            $min = $this->has('min_stake') ? $this->input('min_stake') : $product?->min_stake;
            $max = $this->has('max_stake') ? $this->input('max_stake') : $product?->max_stake;

            if ($min !== null && $max !== null && (float) $min > (float) $max) {
                $validator->errors()->add('min_stake', 'The minimum stake may not be above the maximum stake.');
            }
        }];
    }
}
