<?php

namespace App\Http\Requests\Predict;

use App\Http\Requests\Concerns\FailsWithApiError;
use App\Models\PredictMarket;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Filters for the market list (players and GMS).
 */
class ListPredictMarketsRequest extends FormRequest
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
            'category' => ['nullable', 'in:'.implode(',', config('predict.categories'))],
            'country' => ['nullable', 'in:'.implode(',', array_keys(config('predict.countries')))],
            'source' => ['nullable', 'in:'.PredictMarket::SOURCE_CUSTOM.','.PredictMarket::SOURCE_POLYMARKET],
            'status' => ['nullable', 'in:'.implode(',', PredictMarket::STATUSES)],
            'sort' => ['nullable', 'in:trending,closing_soon,new'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
