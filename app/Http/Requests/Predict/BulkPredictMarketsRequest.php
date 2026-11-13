<?php

namespace App\Http\Requests\Predict;

use App\Http\Requests\Concerns\FailsWithApiError;
use App\Models\PredictMarket;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GMS publishes or rejects many drafts at once: by `ids`, or by a filter. At most `limit` (up to 500) drafts are
 * handled per call; the answer says how many still match, so GMS can call again. A refused draft stays a draft, so
 * a filter call answers `next_after_id`: sent back as `after_id`, the next call starts past the drafts already tried.
 */
class BulkPredictMarketsRequest extends FormRequest
{
    use FailsWithApiError;

    public const MAX = 500;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $isReject = str_ends_with((string) $this->route()?->uri(), 'reject');

        return [
            'admin_user' => ['required', 'string', 'max:100'],
            'reason' => [$isReject ? 'required' : 'nullable', 'string', 'max:255'],
            'ids' => ['required_without:filter', 'prohibits:filter', 'array', 'min:1', 'max:'.self::MAX],
            'ids.*' => ['integer', 'distinct'],
            'filter' => ['required_without:ids', 'array'],
            'filter.source' => ['nullable', 'in:'.PredictMarket::SOURCE_CUSTOM.','.PredictMarket::SOURCE_POLYMARKET],
            'filter.category' => ['nullable', 'in:'.implode(',', config('predict.categories'))],
            'filter.min_volume' => ['nullable', 'numeric', 'min:0'],
            'filter.max_volume' => ['nullable', 'numeric', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX],
            'after_id' => ['nullable', 'prohibits:ids', 'integer', 'min:0'],
        ];
    }
}
