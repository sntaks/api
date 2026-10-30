<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\CustomerStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * GMS (full key) sets a customer's status with a reason, and reads the history of status changes.
 */
class CustomerStatusController extends Controller
{
    public function __construct(private CustomerStatusService $statuses) {}

    public function update(Request $request, string $encryptedIdentifier): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'integer', Rule::in(Customer::STATUSES)],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'admin_user' => ['required', 'string', 'max:100'],
        ]);

        $customer = Customer::find($encryptedIdentifier);

        if (! $customer) {
            return response()->json(['success' => false, 'message' => 'Customer not found'], 404);
        }

        $previous = $this->statuses->change($customer, (int) $validated['status'], $validated['reason'], $this->actorFor($request), $validated['admin_user']);

        return response()->json([
            'success' => true,
            'message' => 'Status updated',
            'data' => ['status' => (int) $customer->status, 'previous_status' => $previous],
        ]);
    }

    public function index(Request $request, string $encryptedIdentifier): JsonResponse
    {
        $perPage = (int) ($request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:200']])['per_page'] ?? 50);

        if (! Customer::withTrashed()->whereKey($encryptedIdentifier)->exists()) {
            return response()->json(['success' => false, 'message' => 'Customer not found'], 404);
        }

        $page = DB::table('customer_status_changes')
            ->where('customer_id', $encryptedIdentifier)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        return $this->pagedResponse($page, fn (object $row) => [
            'from_status' => $row->from_status,
            'to_status' => $row->to_status,
            'reason' => $row->reason,
            'admin_user' => $row->admin_user,
            'actor' => $row->actor,
            'created_at' => $row->created_at,
        ]);
    }
}
