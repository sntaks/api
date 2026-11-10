<?php

namespace App\Http\Requests\Predict;

use App\Http\Requests\Concerns\FailsWithApiError;
use App\Models\PredictMarket;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An admin action on a market (publish, suspend, resume, close, resolve, void, block): who did it and why.
 * Resolve needs the result; void and block need a reason; block needs the customer.
 */
class PredictAdminActionRequest extends FormRequest
{
    use FailsWithApiError;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $action = $this->route()?->getActionMethod();
        $needsReason = in_array($action, ['void', 'block'], true);

        return [
            'admin_user' => ['required', 'string', 'max:100'],
            'reason' => [$needsReason ? 'required' : 'nullable', 'string', 'max:255'],
            'result' => [$action === 'resolve' ? 'required' : 'prohibited', 'in:'.implode(',', PredictMarket::SIDES)],
            'customer_id' => [$action === 'block' ? 'required' : 'prohibited', 'integer', 'exists:customers,id'],
        ];
    }
}
