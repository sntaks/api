<?php

namespace App\Http\Requests;

use App\Models\Customer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBetShopRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Details only: shop_no is fixed (owners use it in their paybill bill ref) and status has its own actions.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'owner_name' => ['sometimes', 'required', 'string', 'max:255'],
            'owner_phone' => ['sometimes', 'required', 'string', 'regex:/^254[17]\d{8}$/'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'owner_phone.regex' => 'The owner phone must be a Kenyan mobile number.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('owner_phone')) {
            $this->merge(['owner_phone' => Customer::canonicalPhone($this->input('owner_phone')) ?? $this->input('owner_phone')]);
        }
    }
}
