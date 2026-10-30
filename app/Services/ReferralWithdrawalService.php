<?php

namespace App\Services;

use App\Exceptions\MpesaApiException;
use App\Models\Customer;
use App\Models\ReferralWallet;
use App\Models\ReferralWithdrawal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Pays referral wallet balances out to M-Pesa from the referral shortcode (mpesa.referral_b2c). The main
 * B2C shortcode, WithdrawalService and B2CService are not involved.
 *
 * The wallet is debited first, then the B2C request is sent. A request Safaricom rejects is reversed at
 * once. A request whose outcome is unknown (network error, no reply) stays pending and is never refunded
 * automatically, because Safaricom may still pay it.
 */
class ReferralWithdrawalService
{
    public function __construct(
        private LedgerService $ledgerService,
        private MpesaService $mpesaService,
        private WithdrawalRules $rules,
    ) {}

    /**
     * @return array{success: bool, message: string, status_code: int, withdrawal?: ReferralWithdrawal, code?: string, retry_after?: int}
     */
    public function withdraw(Customer $customer, int $amount, ?string $actor = null): array
    {
        if (! config('referrals.withdrawals.enabled')) {
            return $this->refused('Referral withdrawals are switched off', 403);
        }

        $allowed = $this->rules->check($customer);
        if (! $allowed->allowed) {
            return $this->refused($allowed->message, $allowed->statusCode) + ['code' => $allowed->code] + $allowed->extra;
        }

        if (! $this->isConfigured()) {
            Log::channel('mpesa')->error('MPESA Referral B2C is not configured: set the MPESA_REFERRAL_B2C_* variables');

            return $this->refused('Referral withdrawals are not available right now', 503);
        }

        $minimum = (int) config('referrals.withdrawals.minimum');
        if ($amount < $minimum) {
            return $this->refused("The minimum referral withdrawal is KES {$minimum}", 422);
        }

        $phone = $this->mpesaPhone($customer->phone_no);
        if (! $phone) {
            return $this->refused('Customer phone number is missing or not a valid M-Pesa number', 400);
        }

        $wallet = $customer->referralWallet;
        if (! $wallet) {
            return $this->refused('Insufficient referral wallet balance', 400);
        }

        $withdrawal = DB::transaction(function () use ($customer, $wallet, $amount, $phone, $actor) {
            $wallet = ReferralWallet::lockForUpdate()->find($wallet->id);

            if ((float) $wallet->balance < $amount) {
                return null;
            }

            $withdrawal = ReferralWithdrawal::create([
                'customer_id' => $customer->id,
                'referral_wallet_id' => $wallet->id,
                'amount' => $amount,
                'phone_no' => $phone,
                'status' => ReferralWithdrawal::STATUS_PENDING,
                'requested_by' => $actor,
            ]);

            $entry = $this->ledgerService->recordReferralWithdrawal($withdrawal, $wallet, $amount);
            $withdrawal->update(['ledger_entry_id' => $entry->id]);

            return $withdrawal;
        });

        if (! $withdrawal) {
            return $this->refused('Insufficient referral wallet balance', 400);
        }

        $this->rules->record($customer);

        try {
            $response = $this->mpesaService->referralB2c([
                'Amount' => $amount,
                'PartyB' => $phone,
                'Remarks' => 'Referral Payout',
                'Occasion' => 'REFERRAL-'.$withdrawal->id,
            ]);
        } catch (MpesaApiException|InvalidArgumentException $e) {
            // Safaricom answered with an error, or the request was never sent: nothing was paid.
            Log::channel('mpesa')->error('MPESA Referral B2C Error: '.$e->getMessage(), ['referral_withdrawal_id' => $withdrawal->id]);

            $this->fail($withdrawal, $e instanceof MpesaApiException ? (string) $e->errorCode : '', $e->getMessage());

            return $this->refused('The M-Pesa payout could not be sent', 502, $withdrawal->fresh());
        } catch (Throwable $e) {
            Log::channel('mpesa')->error('MPESA Referral B2C Unknown Outcome: '.$e->getMessage(), ['referral_withdrawal_id' => $withdrawal->id]);

            return [
                'success' => false,
                'message' => 'The payout outcome is unknown; it is kept pending for review',
                'status_code' => 202,
                'withdrawal' => $withdrawal->fresh(),
            ];
        }

        Log::channel('mpesa')->info('MPESA Referral B2C Response', $response + ['referral_withdrawal_id' => $withdrawal->id]);

        if (isset($response['ResponseCode']) && $response['ResponseCode'] == 0 && ! empty($response['ConversationID'])) {
            $withdrawal->update([
                'status' => ReferralWithdrawal::STATUS_PROCESSING,
                'conversation_id' => $response['ConversationID'],
                'originator_conversation_id' => $response['OriginatorConversationID'] ?? null,
            ]);

            return ['success' => true, 'message' => 'Referral payout sent', 'status_code' => 201, 'withdrawal' => $withdrawal->fresh()];
        }

        $this->fail($withdrawal, (string) ($response['ResponseCode'] ?? ''), $response['ResponseDescription'] ?? 'Unknown error');

        return $this->refused('The M-Pesa payout was rejected', 502, $withdrawal->fresh());
    }

    /**
     * Apply a B2C result callback from the referral shortcode. Accepts Safaricom's nested
     * `{"Result": {...}}` body and a flat one. Results for unknown or already settled withdrawals are
     * ignored, so a repeated callback changes nothing.
     *
     * @param  array<string, mixed>  $payload
     * @return array{status: string, referral_withdrawal_id?: int}
     */
    public function handleResult(array $payload): array
    {
        Log::channel('mpesa')->info('MPESA Referral B2C Result', $payload);

        $result = is_array($payload['Result'] ?? null) && isset($payload['Result']['ConversationID'])
            ? $payload['Result']
            : $payload;

        $conversationId = (string) ($result['ConversationID'] ?? '');

        if ($conversationId === '') {
            return ['status' => 'not_found'];
        }

        return DB::transaction(function () use ($result, $conversationId) {
            $withdrawal = ReferralWithdrawal::where('conversation_id', $conversationId)->lockForUpdate()->first();

            if (! $withdrawal) {
                Log::channel('mpesa')->info('MPESA Referral B2C Withdrawal Not Found', ['conversation_id' => $conversationId]);

                return ['status' => 'not_found'];
            }

            if ($withdrawal->status !== ReferralWithdrawal::STATUS_PROCESSING) {
                return ['status' => 'already_settled', 'referral_withdrawal_id' => $withdrawal->id];
            }

            $resultCode = (string) ($result['ResultCode'] ?? '');

            if ($resultCode === '0') {
                $withdrawal->update([
                    'status' => ReferralWithdrawal::STATUS_COMPLETED,
                    'mpesa_receipt' => $result['TransactionID'] ?? null,
                    'result_code' => $resultCode,
                    'result_desc' => $result['ResultDesc'] ?? null,
                    'completed_at' => now(),
                ]);

                return ['status' => 'completed', 'referral_withdrawal_id' => $withdrawal->id];
            }

            $this->fail($withdrawal, $resultCode, $result['ResultDesc'] ?? 'Payout failed');

            return ['status' => 'failed', 'referral_withdrawal_id' => $withdrawal->id];
        });
    }

    /**
     * Settle a withdrawal left pending or processing, by hand, once finance has looked the payout up on the
     * referral shortcode. `completed` needs the M-Pesa receipt; `failed` gives the money back to the
     * referral wallet. Settled withdrawals cannot be changed.
     *
     * @return array{success: bool, message: string, status_code: int, withdrawal?: ReferralWithdrawal}
     */
    public function settle(int $withdrawalId, string $outcome, ?string $receipt, string $note, ?string $actor): array
    {
        return DB::transaction(function () use ($withdrawalId, $outcome, $receipt, $note, $actor) {
            $withdrawal = ReferralWithdrawal::lockForUpdate()->find($withdrawalId);

            if (! $withdrawal) {
                return $this->refused('Referral withdrawal not found', 404);
            }

            if (! in_array($withdrawal->status, [ReferralWithdrawal::STATUS_PENDING, ReferralWithdrawal::STATUS_PROCESSING], true)) {
                return $this->refused("The withdrawal is already {$withdrawal->status}", 409, $withdrawal);
            }

            $settlement = ['settled_by' => $actor, 'settlement_note' => $note];

            if ($outcome === ReferralWithdrawal::STATUS_COMPLETED) {
                $receiptTaken = ReferralWithdrawal::where('mpesa_receipt', $receipt)->whereKeyNot($withdrawal->id)->exists();

                if ($receiptTaken) {
                    return $this->refused('That M-Pesa receipt is already on another referral withdrawal', 409, $withdrawal);
                }

                $withdrawal->update([
                    'status' => ReferralWithdrawal::STATUS_COMPLETED,
                    'mpesa_receipt' => $receipt,
                    'completed_at' => now(),
                ] + $settlement);

                return ['success' => true, 'message' => 'Referral withdrawal marked completed', 'status_code' => 200, 'withdrawal' => $withdrawal->fresh()];
            }

            $this->fail($withdrawal, 'manual', $note, $settlement);

            return ['success' => true, 'message' => 'Referral withdrawal marked failed and refunded', 'status_code' => 200, 'withdrawal' => $withdrawal->fresh()];
        });
    }

    /**
     * Mark a withdrawal failed and give the money back to the referral wallet. Does nothing if it is
     * already completed or failed.
     *
     * @param  array<string, mixed>  $extra  more columns to save with the failure
     */
    private function fail(ReferralWithdrawal $withdrawal, string $resultCode, string $resultDesc, array $extra = []): void
    {
        DB::transaction(function () use ($withdrawal, $resultCode, $resultDesc, $extra) {
            $withdrawal = ReferralWithdrawal::lockForUpdate()->find($withdrawal->id);

            if (! in_array($withdrawal->status, [ReferralWithdrawal::STATUS_PENDING, ReferralWithdrawal::STATUS_PROCESSING], true)) {
                return;
            }

            $wallet = ReferralWallet::lockForUpdate()->find($withdrawal->referral_wallet_id);
            $reversal = $this->ledgerService->reverseReferralWithdrawal($withdrawal->ledgerEntry, $wallet);

            $withdrawal->update([
                'status' => ReferralWithdrawal::STATUS_FAILED,
                'result_code' => $resultCode,
                'result_desc' => mb_substr($resultDesc, 0, 255),
                'reversal_entry_id' => $reversal->id,
                'failed_at' => now(),
            ] + $extra);
        });
    }

    /**
     * Whether every setting the referral payout needs is present, so a withdrawal is never debited for a
     * request that cannot be sent.
     */
    public function isConfigured(): bool
    {
        $app = config('mpesa.apps.referral_b2c', []);
        $b2c = config('mpesa.referral_b2c', []);

        return filled($app['consumer_key'] ?? null)
            && filled($app['consumer_secret'] ?? null)
            && filled($b2c['initiator_name'] ?? null)
            && filled($b2c['security_credential'] ?? null)
            && filled($b2c['short_code'] ?? null)
            && filled($b2c['result_url'] ?? null)
            && filled($b2c['timeout_url'] ?? null);
    }

    /**
     * The customer's number as M-Pesa expects it (2547XXXXXXXX or 2541XXXXXXXX), or null when it is not one.
     */
    public function mpesaPhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);

        $normalized = match (true) {
            (bool) preg_match('/^254[17]\d{8}$/', $digits) => $digits,
            (bool) preg_match('/^0[17]\d{8}$/', $digits) => '254'.substr($digits, 1),
            (bool) preg_match('/^[17]\d{8}$/', $digits) => '254'.$digits,
            default => null,
        };

        return $normalized;
    }

    /**
     * @return array{success: bool, message: string, status_code: int, withdrawal?: ReferralWithdrawal}
     */
    private function refused(string $message, int $statusCode, ?ReferralWithdrawal $withdrawal = null): array
    {
        return array_filter([
            'success' => false,
            'message' => $message,
            'status_code' => $statusCode,
            'withdrawal' => $withdrawal,
        ], fn ($value) => $value !== null);
    }
}
