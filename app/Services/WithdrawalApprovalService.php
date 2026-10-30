<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Transaction;
use App\Models\Withdraw;
use App\Models\WithdrawalApproval;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Decides a withdrawal held for approval (D15), exactly once. GMS approves it (M-Pesa pays the phone that was
 * verified when it was asked for) or rejects it (the hold is refunded); the player may cancel it (refunded).
 * Kadi learns of a refund through the wallet webhook, which every wallet balance change sends.
 */
class WithdrawalApprovalService
{
    public function __construct(
        private LedgerService $ledgerService,
        private WithdrawalService $withdrawals,
    ) {}

    /**
     * @return array{approval?: WithdrawalApproval, refusal?: GateResult, payout?: array{success: bool, message: string, status_code: int}}
     */
    public function approve(int $withdrawId, string $adminUser, ?string $actor): array
    {
        $decided = $this->decide($withdrawId, null, WithdrawalApproval::STATUS_APPROVED, $adminUser, null, $actor);

        if (isset($decided['refusal'])) {
            return $decided;
        }

        // Outside the decision's lock: the approval is final before M-Pesa is asked, so a retry cannot pay twice.
        $approval = $decided['approval'];
        $withdraw = Withdraw::findOrFail($approval->withdraw_id);
        $transaction = Transaction::findOrFail($withdraw->transaction_id);

        try {
            $payout = $this->withdrawals->sendPayout($transaction, $withdraw, $approval->phone_no);
        } catch (Throwable $e) {
            // A timeout or connection failure: M-Pesa may have paid, so nothing is refunded. Finance checks the
            // M-Pesa statement; the withdrawal says what happened, and stuck_withdrawals keeps showing it.
            Log::channel('mpesa')->error('MPESA B2C Approved Payout Not Confirmed: '.$e->getMessage(), ['withdraw_id' => $withdraw->id]);
            $withdraw->update(['error_message' => 'Payout not confirmed: '.mb_substr($e->getMessage(), 0, 200)]);

            $payout = ['success' => false, 'unknown' => true, 'message' => 'Payout not confirmed', 'status_code' => 504];
        }

        return ['approval' => $approval->fresh(), 'payout' => $payout];
    }

    /**
     * @return array{approval?: WithdrawalApproval, refusal?: GateResult}
     */
    public function reject(int $withdrawId, string $adminUser, string $reason, ?string $actor): array
    {
        return $this->decide($withdrawId, null, WithdrawalApproval::STATUS_REJECTED, $adminUser, $reason, $actor);
    }

    /**
     * The player takes back their own held withdrawal. Another player's withdrawal is not found.
     *
     * @return array{approval?: WithdrawalApproval, refusal?: GateResult}
     */
    public function cancel(Customer $customer, int $withdrawId, ?string $actor): array
    {
        return $this->decide($withdrawId, $customer, WithdrawalApproval::STATUS_CANCELLED, null, 'Cancelled by the player', $actor);
    }

    /**
     * Lock the approval, require it to be awaiting, record the decision, and for a rejection or cancellation
     * refund the hold, all in one transaction.
     *
     * @return array{approval?: WithdrawalApproval, refusal?: GateResult}
     */
    private function decide(int $withdrawId, ?Customer $owner, string $status, ?string $adminUser, ?string $reason, ?string $actor): array
    {
        return DB::transaction(function () use ($withdrawId, $owner, $status, $adminUser, $reason, $actor) {
            $approval = WithdrawalApproval::where('withdraw_id', $withdrawId)
                ->when($owner, fn ($query) => $query->where('customer_id', $owner->id))
                ->lockForUpdate()
                ->first();

            if (! $approval) {
                return ['refusal' => GateResult::refuse('not_found', 'No withdrawal waiting for approval has that id', 404)];
            }

            if ($approval->status !== WithdrawalApproval::STATUS_AWAITING) {
                return ['refusal' => GateResult::refuse('not_awaiting', 'This withdrawal has already been decided', 409, ['status' => $approval->status])];
            }

            $approval->update([
                'status' => $status,
                'decided_by' => $actor,
                'admin_user' => $adminUser,
                'reason' => $reason,
                'decided_at' => now(),
            ]);

            if ($status !== WithdrawalApproval::STATUS_APPROVED) {
                $withdraw = Withdraw::findOrFail($approval->withdraw_id);

                $this->ledgerService->reverseWithdrawal($withdraw);

                Transaction::whereKey($withdraw->transaction_id)->update(['payment_id' => $withdraw->id, 'status' => 3]);
                $withdraw->update(['disburse' => 3, 'error_message' => ($status === WithdrawalApproval::STATUS_CANCELLED ? 'Cancelled: ' : 'Rejected: ').$reason]);
            }

            return ['approval' => $approval->fresh()];
        });
    }
}
