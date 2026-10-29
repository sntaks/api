<?php

namespace App\Http\Requests;

use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreB2CPaymentRequest extends FormRequest
{
    /**
     * Money leaves the main shortcode with no wallet debited, so only callers from an allowlisted IP
     * (`mpesa.b2c.send_allowed_ips`) may send. An empty list refuses everyone.
     */
    public function authorize(): bool
    {
        return in_array($this->ip(), config('mpesa.b2c.send_allowed_ips', []), true);
    }

    protected function failedAuthorization(): never
    {
        throw new HttpResponseException(response()->json([
            'message' => 'This caller is not allowed to send B2C payments',
            'code' => 'ip_not_allowed',
        ], 403));
    }

    /**
     * Read the phone as a Kenyan mobile number (07.., 01.., 2547.., +2547..) in the 254 form M-Pesa
     * expects. A number that cannot be read is left as sent, so the regex rule rejects it.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('phone')) {
            $this->merge(['phone' => Customer::canonicalPhone((string) $this->input('phone')) ?? $this->input('phone')]);
        }
    }

    /**
     * M-Pesa B2C pays whole shillings, from KES 10.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'regex:/^254[17]\d{8}$/'],
            'amount' => ['required', 'integer', 'min:10'],
            'remarks' => ['sometimes', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => 'The phone must be a Kenyan mobile number, e.g. 0712345678 or 254712345678.',
        ];
    }
}
