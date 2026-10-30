<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Deposit;
use App\Models\LedgerEntry;
use App\Models\RiskEvent;
use App\Models\StkPush;
use App\Models\Wallet;

/**
 * Credits a plain M-Pesa deposit to a customer's wallet, the one way it is done for C2B payments and for
 * unmatched deposits resolved later: ledger `deposit`, excise duty when it applies, the transaction row and
 * the referral first-deposit hook. Call inside a DB transaction.
 */
class WalletDepositService
{
    public function __construct(
        private LedgerService $ledgerService,
        private ExciseDutyService $exciseDutyService,
        private ReferralService $referralService,
        private PlayerGate $gate,
    ) {}

    /**
     * The customer account number a bill reference points at: the part before the first `#`, normalised
     * by normalizeAccountNo().
     */
    public function accountNoFromBillRef(?string $billRef): string
    {
        return $this->normalizeAccountNo(explode('#', (string) $billRef)[0]);
    }

    /**
     * Account numbers are `KK-` plus 13 hex characters (e.g. KK-6AA8B1DAAF392). A payer's typing slips
     * (spaces, lower case, a missing dash) are corrected: ` kk6aa8b1daaf392 ` becomes KK-6AA8B1DAAF392.
     * Anything else keeps the older rules: 2547XXXXXXXX and 07XXXXXXXX become 7XXXXXXXX (from when
     * account numbers were phone numbers), and every other value is returned unchanged.
     */
    public function normalizeAccountNo(string $rawAccountNo): string
    {
        $compact = strtoupper(preg_replace('/\s+/', '', $rawAccountNo));

        if (preg_match('/^KK-?([0-9A-F]{13})$/', $compact, $match)) {
            return 'KK-'.$match[1];
        }

        if (preg_match('/^2547\d{8}$/', $rawAccountNo)) {
            return substr($rawAccountNo, 3);
        }

        if (preg_match('/^07\d{8}$/', $rawAccountNo)) {
            return substr($rawAccountNo, 1);
        }

        return $rawAccountNo;
    }

    /**
     * Credit the full deposit to the customer's wallet (creating the wallet if needed) and return the
     * `deposit` ledger entry.
     */
    public function credit(Deposit $deposit, Customer $customer): LedgerEntry
    {
        // Money that has arrived is always credited (D18); a deposit the player's own rules would refuse is
        // flagged for GMS to review.
        $canDeposit = $this->assess($deposit, $customer);

        $wallet = Wallet::firstOrCreate(
            ['customer_id' => $customer->id],
            ['balance' => 0]
        );

        $wallet = Wallet::lockForUpdate()->find($wallet->id);

        $ledgerEntry = $this->ledgerService->recordDeposit($deposit, $wallet, (float) $deposit->trans_amount);

        $exciseDutyCharge = $this->exciseDutyService->chargeIfApplicable($deposit, $wallet, ExciseDutyService::KIND_WALLET_DEPOSIT);

        $wallet->transactions()->create([
            'payment_id' => $deposit->id,
            'payment_ref' => $deposit->trans_id,
            'payment_type' => Deposit::class,
            'amount' => $exciseDutyCharge?->net_amount ?? $deposit->trans_amount,
            'status' => 2,
            'balance_before' => $ledgerEntry->balance_before,
            'balance_after' => $wallet->balance,
        ]);

        $this->referralService->recordDeposit($deposit, $customer->id);

        if (! $canDeposit->allowed) {
            $this->flag($deposit, $customer, $canDeposit);
        }

        return $ledgerEntry;
    }

    /**
     * Whether the player's own rules would have taken this deposit, decided before it is credited so it is
     * not counted against itself. An STK top-up's money settles its push first, so the push stops counting
     * as pending and the same money is not counted twice.
     */
    public function assess(Deposit $deposit, Customer $customer): GateResult
    {
        $push = StkPush::where('customer_id', $customer->id)
            ->whereNull('settled_at')
            ->where('amount', (float) $deposit->trans_amount)
            ->where('created_at', '>', now()->subMinutes((int) config('responsible_gambling.stk_pending_minutes')))
            ->oldest('id')
            ->first();

        $push?->update(['settled_at' => now(), 'deposit_id' => $deposit->id]);

        return $this->gate->canDeposit($customer, (float) $deposit->trans_amount);
    }

    /**
     * Record a responsible-gambling flag for GMS: a deposit credited while the player was inactive, on a
     * break, or over their own limit.
     */
    public function flag(Deposit $deposit, Customer $customer, GateResult $refusal): void
    {
        $type = match ($refusal->code) {
            'player_excluded' => 'rg_deposit_while_excluded',
            'deposit_limit' => 'rg_deposit_over_limit',
            default => 'rg_deposit_inactive',
        };

        RiskEvent::create([
            'ip_address' => request()?->ip() ?? 'system',
            'customer_id' => $customer->id,
            'event_type' => $type,
            'severity' => 'medium',
            'details' => [
                'deposit_id' => $deposit->id,
                'trans_id' => $deposit->trans_id,
                'amount' => (float) $deposit->trans_amount,
                'remaining' => $refusal->extra['remaining'] ?? null,
                'excluded_until' => $refusal->extra['excluded_until'] ?? null,
                'status' => (int) $customer->status,
            ],
            'action_taken' => 'flagged',
            'created_at' => now(),
        ]);
    }
}
