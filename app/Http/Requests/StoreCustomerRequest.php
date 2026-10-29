<?php

namespace App\Http\Requests;

use App\Rules\NotAPlayerNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreCustomerRequest extends FormRequest
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
            'google_id' => 'string|unique:customers,google_id',
            'account_no' => ['required', 'string', 'unique:customers,account_no', new NotAPlayerNumber],
            'referral_code' => 'string',
            'promo_code' => 'nullable|string|max:30',
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:customers,email',
            'id_no' => 'string|unique:customers,id_no',
            'phone_no' => 'sometimes|string|unique:customers,phone_no',
        ];
    }
}
