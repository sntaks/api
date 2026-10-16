<?php

namespace App\Http\Requests;

use App\Models\Customer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreBetShopRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * shop_no goes into the paybill bill ref (SHOP-<shop_no>), so it is short, letters and digits only,
     * stored upper case, and never changes after the shop is created.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'shop_no' => ['required', 'string', 'regex:/^[A-Z0-9]{2,10}$/', 'unique:bet_shops,shop_no'],
            'name' => ['required', 'string', 'max:255'],
            'owner_name' => ['required', 'string', 'max:255'],
            'owner_phone' => ['required', 'string', 'regex:/^254[17]\d{8}$/'],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'shop_no.regex' => 'The shop number must be 2 to 10 letters or digits.',
            'owner_phone.regex' => 'The owner phone must be a Kenyan mobile number.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(array_filter([
            'shop_no' => $this->has('shop_no') ? strtoupper(trim((string) $this->input('shop_no'))) : null,
            'owner_phone' => $this->has('owner_phone') ? (Customer::canonicalPhone($this->input('owner_phone')) ?? $this->input('owner_phone')) : null,
        ], fn ($value) => $value !== null));
    }
}
