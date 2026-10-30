<?php

namespace App\Services;

use App\Exceptions\MpesaApiException;
use App\Models\Customer;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Models\Withdraw;
use App\Models\WithdrawalApproval;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WithdrawalService
{
    public function __construct(
        private LedgerService $ledgerService,
        private MpesaService $mpesaService,
        private SignupBonusService $signupBonus,
        private WithdrawalRules $rules,
    ) {}

    public function initiateWithdrawal(string $identifier, float $amount, ?string $actor = null): array
    {
        $customer = Customer::where('id', $identifier)
            ->orWhere('account_no', $identifier)
            ->first();

        if (! $customer) {
            return ['success' => false, 'message' => 'Customer not found', 'status_code' => 404];
        }

        $wallet = $customer->wallet;
        if (! $wallet) {
            return ['success' => false, 'message' => 'Wallet not found', 'status_code' => 404];
        }

        $phone_no = $customer->phone_no ?? null;
        if (! $phone_no) {
            return ['success' => false, 'message' => 'Customer phone number not found', 'status_code' => 400];
        }

        $allowed = $this->rules->check($customer);
        if (! $allowed->allowed) {
            return ['success' => false, 'message' => $allowed->message, 'status_code' => $allowed->statusCode, 'code' => $allowed->code] + $allowed->extra;
        }

        $held = $amount >= (float) config('responsible_gambling.approval_threshold');

        // Debit and ledger the withdrawal up front so a failed payout can be reversed against a real entry. A
        // withdrawal of approval_threshold or more is held for approval in the same transaction (D15).
        $reserved = DB::transaction(function () use ($wallet, $amount, $customer, $phone_no, $held, $actor) {
            $lockedWallet = Wallet::lockForUpdate()->find($wallet->id);

            if ($lockedWallet->balance < $amount) {
                return null;
            }

            if ($this->signupBonus->wouldSpendLockedBonus($lockedWallet, (float) $amount)) {
                return $this->signupBonus->lockedRefusal($lockedWallet);
            }

            $transaction = $lockedWallet->transactions()->create([
                'payment_id' => null,
                'payment_ref' => null,
                'payment_type' => Withdraw::class,
                'amount' => $amount,
                'status' => 1,
            ]);

            $withdraw = Withdraw::create([
                'transaction_id' => $transaction->id,
                'amount' => $amount,
                'disburse' => 1,
            ]);

            $ledgerEntry = $this->ledgerService->recordWithdrawal($withdraw, $lockedWallet, (float) $amount);

            $transaction->update([
                'payment_id' => $withdraw->id,
                'balance_before' => $ledgerEntry->balance_before,
                'balance_after' => $ledgerEntry->balance_after,
            ]);

            if ($held) {
                WithdrawalApproval::create([
                    'withdraw_id' => $withdraw->id,
                    'customer_id' => $customer->id,
                    'amount' => $amount,
                    'phone_no' => $phone_no,
                    'status' => WithdrawalApproval::STATUS_AWAITING,
                    'requested_by' => $actor,
                ]);
            }

            return [$transaction, $withdraw, $ledgerEntry];
        });

        if ($reserved === null) {
            return ['success' => false, 'message' => 'Insufficient wallet balance for withdrawal', 'status_code' => 400];
        }

        if (isset($reserved['success'])) {
            return $reserved;
        }

        [$transaction, $withdraw, $ledgerEntry] = $reserved;

        $this->rules->record($customer);

        if ($held) {
            return [
                'success' => true,
                'held' => true,
                'message' => 'awaiting_approval',
                'withdrawal_id' => $withdraw->id,
                'ledger_entry_id' => $ledgerEntry->entry_id,
                'status_code' => 202,
            ];
        }

        $result = $this->sendPayout($transaction, $withdraw, $phone_no);

        return $result['success'] ? $result + ['ledger_entry_id' => $ledgerEntry->entry_id] : $result;
    }

    /**
     * Ask M-Pesa to pay a reserved withdrawal to $phone. Accepted: the transaction waits for the B2C result.
     * Refused or failed: the hold is reversed at once and the withdrawal marked failed. Used when the
     * withdrawal is requested, and when a held one is approved.
     *
     * @return array{success: bool, message: string, status_code: int}
     */
    public function sendPayout(Transaction $transaction, Withdraw $withdraw, string $phone): array
    {
        $userParams = [
            'Amount' => $withdraw->amount,
            'PartyB' => $phone,
            'Remarks' => 'Business Payment',
        ];

        try {
            $response = $this->mpesaService->b2c($userParams);
        } catch (MpesaApiException $e) {
            Log::channel('mpesa')->error('MPESA B2C Error: '.$e->getMessage());

            $this->ledgerService->reverseWithdrawal($withdraw);

            $transaction->update([
                'payment_id' => $withdraw->id,
                'status' => 3,
            ]);

            $withdraw->update([
                'disburse' => 3,
                'error_message' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => $e->getMessage(), 'status_code' => 500];
        }

        Log::channel('mpesa')->info('MPESA B2C Response', $response);

        if (isset($response['ResponseCode']) && $response['ResponseCode'] == 0) {
            $transaction->update([
                'payment_id' => $withdraw->id,
                'payment_ref' => $response['ConversationID'],
                'status' => 2,
            ]);

            $withdraw->update([
                'disburse' => 2,
                'receipt' => $response['ConversationID'] ?? 'N/A',
            ]);

            return [
                'success' => true,
                'message' => 'success',
                'status_code' => 201,
            ];
        } else {
            $this->ledgerService->reverseWithdrawal($withdraw);

            $transaction->update([
                'payment_id' => $withdraw->id,
                'status' => 3,
            ]);

            // The code goes in the message, not in `receipt`: receipts are unique, and M-Pesa repeats its codes.
            $withdraw->update([
                'disburse' => 3,
                'error_message' => 'M-Pesa ResponseCode '.($response['ResponseCode'] ?? '?').': '.($response['ResponseDescription'] ?? 'Unknown Error'),
            ]);

            return ['success' => false, 'message' => 'Unknown Error', 'status_code' => 500];
        }
    }
}
