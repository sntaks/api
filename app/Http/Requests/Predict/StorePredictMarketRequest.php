<?php

namespace App\Http\Requests\Predict;

use App\Http\Requests\Concerns\FailsWithApiError;
use App\Models\PredictMarket;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GMS creates a custom pool market as a draft. Money in KES, rates as fractions.
 */
class StorePredictMarketRequest extends FormRequest
{
    use FailsWithApiError;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'admin_user' => ['required', 'string', 'max:100'],
            'engine' => ['nullable', 'in:'.PredictMarket::ENGINE_POOL],
            'question' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'resolution_criteria' => ['required', 'string', 'max:5000'],
            'resolution_source' => ['nullable', 'string', 'max:255'],
            'category' => ['required', 'in:'.implode(',', config('predict.categories'))],
            'image_url' => ['nullable', 'url', 'max:500'],
            'countries' => ['required', 'array', 'min:1'],
            'countries.*' => ['required', 'distinct', 'in:'.implode(',', array_keys(config('predict.countries')))],
            'close_at' => ['required', 'date', 'after:now'],
            'resolution_deadline_at' => ['required', 'date', 'after_or_equal:close_at'],
            'start_probability' => ['required', 'numeric', 'between:0.01,0.99', 'decimal:0,4'],
            'seed' => ['nullable', 'numeric', 'min:100', 'decimal:0,2', 'max:10000000'],
            'cut' => ['nullable', 'numeric', 'between:0,0.5', 'decimal:0,4'],
            'min_stake' => ['nullable', 'numeric', 'gt:0', 'decimal:0,2'],
            'max_stake' => ['nullable', 'numeric', 'gt:0', 'decimal:0,2'],
            'max_payout' => ['nullable', 'numeric', 'gt:0', 'decimal:0,2'],
            'min_multiplier' => ['nullable', 'numeric', 'between:1,2', 'decimal:0,2'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => PredictMarketTerms::check($validator, array_filter($this->only(['start_probability', 'cut', 'min_multiplier', 'min_stake', 'max_stake']), fn ($value) => $value !== null))];
    }
}
