<?php

namespace App\Http\Requests;

use App\Rules\NotAPlayerNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCustomerRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'google_id' => 'string',
            'account_no' => ['string', new NotAPlayerNumber],
            'referral_code' => 'string',
            'name' => 'string',
            'email' => 'email',
            'phone_no' => 'string',
            'status' => 'integer',
        ];
    }
}
