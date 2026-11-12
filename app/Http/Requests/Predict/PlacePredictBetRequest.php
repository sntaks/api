<?php

namespace App\Http\Requests\Predict;

use App\Http\Requests\Concerns\FailsWithApiError;
use App\Models\PredictMarket;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The Kadi site places a player's bet. `reference` is the site's own id for the bet and makes retries safe.
 */
class PlacePredictBetRequest extends FormRequest
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
            'reference' => ['required', 'string', 'max:100'],
            // Required by fixed-odds (imported) markets, ignored by pool markets
            'quote_id' => ['nullable', 'uuid'],
        ];
    }
}
