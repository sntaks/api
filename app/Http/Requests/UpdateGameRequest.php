<?php

namespace App\Http\Requests;

use App\Models\Game;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateGameRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The code is fixed: game keys, launch tokens and ledger products refer to it. A status change needs
     * the reason and the GMS admin, kept in catalog_status_changes.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'launch_url' => ['sometimes', 'required', 'url', 'max:255'],
            'status' => ['sometimes', 'required', 'in:'.implode(',', Game::STATUSES)],
            'sort_order' => ['sometimes', 'required', 'integer', 'min:0'],
            'reason' => ['required_with:status', 'string', 'min:3', 'max:500'],
            'admin_user' => ['required_with:status', 'string', 'max:100'],
        ];
    }
}
