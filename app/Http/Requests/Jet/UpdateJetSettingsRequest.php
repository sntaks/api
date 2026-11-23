<?php

namespace App\Http\Requests\Jet;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * GMS: change the round parameters. House edge (RTP) and the curve are not editable: an owner decision.
 */
class UpdateJetSettingsRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'betting_window_seconds' => ['sometimes', 'integer', 'min:5', 'max:120'],
            'post_round_pause_seconds' => ['sometimes', 'integer', 'min:3', 'max:60'],
            'max_multiplier' => ['sometimes', 'numeric', 'min:2', 'max:10000', 'decimal:0,2'],
            'house_edge' => ['prohibited'],
            'acceleration_k' => ['prohibited'],
            'reason' => ['required', 'string', 'max:255'],
            'admin_user' => ['required', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'house_edge.prohibited' => 'The house edge is read-only: an RTP change is an owner decision.',
            'acceleration_k.prohibited' => 'The flight curve is read-only.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => $validator->errors()->first(),
            'code' => 'validation_failed',
            'errors' => $validator->errors(),
        ], 422));
    }
}
