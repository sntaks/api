<?php

namespace App\Http\Requests\Predict;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The polling feed's cursor: `since` (the last `server_time`) and, to read on after `has_more`, `after_id`.
 */
class PredictUpdatesRequest extends FormRequest
{
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
            'since' => ['required', 'date', 'after:-1 day'],
            'after_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
