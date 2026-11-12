<?php

namespace App\Http\Requests\Predict;

use App\Http\Requests\Concerns\FailsWithApiError;
use App\Models\PredictMarket;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * GMS edits a market: anything while it is a draft, only display fields and times once it is live.
 */
class UpdatePredictMarketRequest extends FormRequest
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
            'reason' => ['nullable', 'string', 'max:255'],
            'question' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'resolution_criteria' => ['sometimes', 'string', 'max:5000'],
            'resolution_source' => ['sometimes', 'nullable', 'string', 'max:255'],
            'category' => ['sometimes', 'in:'.implode(',', config('predict.categories'))],
            'image_url' => ['sometimes', 'nullable', 'url', 'max:500'],
            'countries' => ['sometimes', 'array', 'min:1'],
            'countries.*' => ['required', 'distinct', 'in:'.implode(',', array_keys(config('predict.countries')))],
            'close_at' => ['sometimes', 'date', 'after:now'],
            'resolution_deadline_at' => ['sometimes', 'date'],
            'start_probability' => ['sometimes', 'numeric', 'between:0.01,0.99', 'decimal:0,4'],
            'seed' => ['sometimes', 'numeric', 'min:100', 'decimal:0,2', 'max:10000000'],
            'cut' => ['sometimes', 'numeric', 'between:0,0.5', 'decimal:0,4'],
            'min_stake' => ['sometimes', 'numeric', 'gt:0', 'decimal:0,2'],
            'max_stake' => ['sometimes', 'numeric', 'gt:0', 'decimal:0,2'],
            'max_payout' => ['sometimes', 'numeric', 'gt:0', 'decimal:0,2'],
            'min_multiplier' => ['sometimes', 'numeric', 'between:1,2', 'decimal:0,2'],
            'margin' => ['sometimes', 'numeric', 'between:0,0.5', 'decimal:0,4'],
            'liquidity' => ['sometimes', 'numeric', 'min:100', 'max:10000000', 'decimal:0,2'],
            'max_liability' => ['sometimes', 'numeric', 'gt:0', 'decimal:0,2', 'max:100000000'],
            'max_customer_stake' => ['sometimes', 'numeric', 'gt:0', 'decimal:0,2', 'max:100000000'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $market = ctype_digit((string) $this->route('id')) ? PredictMarket::find((int) $this->route('id')) : null;

            if (! $market) {
                return;
            }

            // Never $this->validated() inside an after hook: it re-runs validation and these hooks
            $data = $this->only(['close_at', 'resolution_deadline_at', 'start_probability', 'cut', 'min_multiplier', 'min_stake', 'max_stake']);
            $closeAt = isset($data['close_at']) ? Carbon::parse($data['close_at']) : $market->close_at;
            $deadline = isset($data['resolution_deadline_at']) ? Carbon::parse($data['resolution_deadline_at']) : $market->resolution_deadline_at;

            if ($deadline->lt($closeAt)) {
                $validator->errors()->add('resolution_deadline_at', 'The resolution deadline must be at or after the close time.');
            }

            // Check the terms as they would be after the edit
            PredictMarketTerms::check($validator, $data + [
                'engine' => $market->engine,
                'start_probability' => $market->start_probability_bp / 10000,
                'cut' => $market->cut_bp / 10000,
                'min_multiplier' => $market->min_multiplier_x100 / 100,
                'min_stake' => $market->min_stake_minor / 100,
                'max_stake' => $market->max_stake_minor / 100,
            ]);
        }];
    }
}
