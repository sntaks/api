<?php

namespace App\Http\Requests\Predict;

use App\Http\Requests\Concerns\FailsWithApiError;
use App\Models\PredictMarket;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The Kadi site asks for a fixed-odds quote for a player's stake on one side of an imported market.
 */
class CreatePredictQuoteRequest extends FormRequest
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
            'market_id' => ['required', 'integer'],
            'side' => ['required', 'in:'.implode(',', PredictMarket::SIDES)],
            'stake' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:99999999.99'],
        ];
    }
}
