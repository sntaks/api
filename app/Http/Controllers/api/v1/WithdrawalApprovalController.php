<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\WithdrawalApproval;
use App\Services\WithdrawalApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Withdrawals of approval_threshold or more, held for approval: GMS (full key) lists and decides them, kadi
 * (site key) lets the player cancel their own.
 */
class WithdrawalApprovalController extends Controller
{
    public function __construct(private WithdrawalApprovalService $approvals) {}

    /**
     * Waiting for a decision, oldest first.
     */
    public function pending(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'customer' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $page = WithdrawalApproval::with(['customer', 'withdraw'])
            ->where('status', WithdrawalApproval::STATUS_AWAITING)
            ->when(isset($filters['customer']), fn ($query) => $query->where('customer_id', $filters['customer']))
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 50));

        return $this->pagedResponse($page, fn (WithdrawalApproval $approval) => $this->present($approval));
    }

    /**
     * Every held withdrawal and its decision, newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(WithdrawalApproval::STATUSES)],
            'customer' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $page = WithdrawalApproval::with(['customer', 'withdraw'])
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->when(isset($filters['customer']), fn ($query) => $query->where('customer_id', $filters['customer']))
            ->when(isset($filters['from']), fn ($query) => $query->where('created_at', '>=', $filters['from']))
            ->when(isset($filters['to']), fn ($query) => $query->where('created_at', '<=', $filters['to']))
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 50));

        return $this->pagedResponse($page, fn (WithdrawalApproval $approval) => $this->present($approval));
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['admin_user' => ['required', 'string', 'max:100']]);

        $result = $this->approvals->approve($this->numericId($id), $validated['admin_user'], $this->actorFor($request));

        if (isset($result['refusal'])) {
            return $result['refusal']->toResponse();
        }

        $payout = $result['payout'];

        // Approved either way. M-Pesa refused at once: the hold has already been refunded (502). No answer from
        // M-Pesa: it may have paid, so nothing is refunded and nobody should approve or retry it (504).
        if ($payout['unknown'] ?? false) {
            return response()->json([
                'success' => false,
                'code' => 'payout_unknown',
                'message' => 'Approved, but M-Pesa did not answer: the payout may or may not have been made. Do not retry; check the M-Pesa statement.',
                'data' => $this->present($result['approval']),
            ], 504);
        }

        return response()->json([
            'success' => $payout['success'],
            'message' => $payout['success'] ? 'Approved: the payout was sent to M-Pesa' : 'Approved, but M-Pesa refused the payout; the player has been refunded',
            'data' => $this->present($result['approval']),
        ], $payout['success'] ? 200 : 502);
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'admin_user' => ['required', 'string', 'max:100'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $result = $this->approvals->reject($this->numericId($id), $validated['admin_user'], $validated['reason'], $this->actorFor($request));

        if (isset($result['refusal'])) {
            return $result['refusal']->toResponse();
        }

        return response()->json(['success' => true, 'message' => 'Rejected: the player has been refunded', 'data' => $this->present($result['approval'])]);
    }

    /**
     * Kadi (site key): the player takes back their own held withdrawal; the money returns to the wallet.
     */
    public function cancel(Request $request, string $encryptedIdentifier, string $id): JsonResponse
    {
        $customer = Customer::find($encryptedIdentifier);

        if (! $customer) {
            return response()->json(['success' => false, 'message' => 'Customer not found'], 404);
        }

        $result = $this->approvals->cancel($customer, $this->numericId($id), $this->actorFor($request));

        if (isset($result['refusal'])) {
            return $result['refusal']->toResponse();
        }

        return response()->json(['success' => true, 'message' => 'Cancelled: the money is back in the wallet', 'data' => $this->present($result['approval'])]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(WithdrawalApproval $approval): array
    {
        return [
            'withdrawal_id' => $approval->withdraw_id,
            'customer_id' => $approval->customer_id,
            'player_no' => $approval->customer?->player_no,
            'name' => $approval->customer?->name,
            'amount' => (float) $approval->amount,
            'phone_no' => $approval->phone_no,
            'status' => $approval->status,
            'requested_by' => $approval->requested_by,
            'requested_at' => $approval->created_at?->toIso8601String(),
            'admin_user' => $approval->admin_user,
            'decided_by' => $approval->decided_by,
            'reason' => $approval->reason,
            'decided_at' => $approval->decided_at?->toIso8601String(),
            'payout_status' => $this->payoutStatus($approval),
            'payout_error' => $approval->withdraw?->error_message,
        ];
    }

    /**
     * Where the money is: pending (waiting for a decision), sent (M-Pesa accepted it), failed (refused or
     * rejected: refunded), cancelled, or unconfirmed (approved but M-Pesa never answered: check the statement).
     */
    private function payoutStatus(WithdrawalApproval $approval): string
    {
        return match (true) {
            $approval->status === WithdrawalApproval::STATUS_AWAITING => 'pending',
            $approval->status === WithdrawalApproval::STATUS_CANCELLED => 'cancelled',
            (int) $approval->withdraw?->disburse === 2 => 'sent',
            (int) $approval->withdraw?->disburse === 3 => 'failed',
            default => 'unconfirmed',
        };
    }

    /**
     * Route ids are not constrained (the route fence fills placeholders with any value); a non-number is not found.
     */
    private function numericId(string $id): int
    {
        return ctype_digit($id) ? (int) $id : 0;
    }
}
