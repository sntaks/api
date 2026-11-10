<?php

namespace App\Http\Requests\Predict;

use App\Http\Requests\Concerns\FailsWithApiError;
use App\Models\PredictMarket;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Preview the estimated multiplier for a stake on one side.
 */
class EstimatePredictBetRequest extends FormRequest
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
            'side' => ['required', 'in:'.implode(',', PredictMarket::SIDES)],
            'stake' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:99999999.99'],
        ];
    }
}
