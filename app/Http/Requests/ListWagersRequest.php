<?php

namespace App\Http\Requests;

use App\Models\Wager;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class ListWagersRequest extends FormRequest
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
        // The customer's own history (site key) is already scoped to them: the GMS-only filters are refused, not ignored
        $gmsOnly = $this->route('encryptedIdentifier') !== null ? ['prohibited'] : ['nullable'];

        return [
            'game' => ['nullable', 'string', 'max:50'],
            'product' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'in:'.implode(',', Wager::STATUSES)],
            'customer' => [...$gmsOnly, 'integer', 'min:1'],
            'reference' => [...$gmsOnly, 'string', 'max:100'],
            'from' => [...$gmsOnly, 'date_format:Y-m-d'],
            'to' => [...$gmsOnly, 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => $validator->errors()->first(),
            'code' => 'validation_failed',
            'retryable' => false,
            'errors' => $validator->errors(),
        ], 422));
    }
}
