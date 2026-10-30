<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\PromoCode;
use App\Models\PromotionCredit;
use App\Models\Wallet;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The signup bonus (config/promotions.php): a one-off credit to a newly verified customer's main wallet,
 * paid from the house wallet and grossed up for excise duty so the customer keeps the net amount. Only
 * customers who signed up with a promo code get it, and the code must still be usable when they verify.
 *
 * The net amount stays locked until the customer has staked that much (games, tournaments, jackpots)
 * since the credit, less stakes refunded since. The locked part cannot be withdrawn, transferred to
 * another wallet or spent on coins. It is worked out from the ledger each time, so every refund path
 * counts without having to update a counter.
 */
class SignupBonusService
{
    private const LOCK_KEY = 'promotions:signup_bonus:grant';

    public function __construct(
        private LedgerService $ledgerService,
        private ExciseDutyService $exciseDuty,
        private ChartOfAccounts $chart,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('promotions.signup_bonus.enabled') && $this->netAmount() > 0;
    }

    public function netAmount(): float
    {
        return (float) config('promotions.signup_bonus.net_amount');
    }

    /**
     * What a bonus granted now costs: the gross the house pays, the duty and what the customer keeps.
     *
     * @return array{gross: float, rate: float, excise: float, net: float}
     */
    public function amounts(): array
    {
        return $this->exciseDuty->grossUpForNet($this->netAmount());
    }

    /**
     * Grant the bonus when the customer qualifies, or return the one already granted. Returns null when
     * they do not qualify; the reason is logged. Safe to call on every verification.
     */
    public function grantIfEligible(Customer $customer): ?PromotionCredit
    {
        $existing = $this->creditFor($customer->id);
        if ($existing) {
            return $existing;
        }

        $reason = $this->ineligibility($customer);
        if ($reason !== null) {
            $this->logSkip($customer, $reason);

            return null;
        }

        return Cache::lock(self::LOCK_KEY, 10)->block(5, fn () => $this->grant($customer));
    }

    /**
     * The customer's signup bonus, if they have one.
     */
    public function creditFor(int $customerId): ?PromotionCredit
    {
        return PromotionCredit::where('customer_id', $customerId)
            ->where('promotion', PromotionCredit::PROMOTION_SIGNUP_BONUS)
            ->first();
    }

    /**
     * How much of the customer's wallet is still locked bonus: the net amount less what has been staked
     * (and not refunded) since the bonus was credited.
     */
    public function lockedAmount(int $customerId): float
    {
        $credit = $this->creditFor($customerId);

        if (! $credit || $credit->ledger_entry_id === null) {
            return 0.0;
        }

        return max(0.0, round((float) $credit->net_amount - $this->wagered($credit), 2));
    }

    /**
     * Stakes from the bonus wallet since the credit, less stakes refunded since. Never below zero.
     */
    public function wagered(PromotionCredit $credit): float
    {
        $stakeTypes = $this->chart->entryTypesFor('stake');
        $refundTypes = $this->chart->entryTypesFor('refund');

        $row = DB::table('ledger_entries')
            ->where('wallet_type', LedgerEntry::WALLET_TYPE_WALLET)
            ->where('wallet_id', $credit->wallet_id)
            ->where('id', '>', $credit->ledger_entry_id)
            ->whereIn('entry_type', array_merge($stakeTypes, $refundTypes))
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN entry_type IN ('.$this->placeholders($stakeTypes).') THEN debit - credit ELSE 0 END), 0) as staked, '
                .'COALESCE(SUM(CASE WHEN entry_type IN ('.$this->placeholders($refundTypes).') THEN credit - debit ELSE 0 END), 0) as refunded',
                array_merge($stakeTypes, $refundTypes)
            )
            ->first();

        return max(0.0, round((float) $row->staked - (float) $row->refunded, 2));
    }

    /**
     * Whether taking $amount out of the wallet other than as a stake would dip into locked bonus.
     */
    public function wouldSpendLockedBonus(Wallet $wallet, float $amount): bool
    {
        $locked = $this->lockedAmount((int) $wallet->customer_id);

        return $locked > 0 && round((float) $wallet->balance - $amount, 2) < $locked;
    }

    /**
     * The refusal returned when a withdrawal, transfer or coin purchase would use locked bonus.
     *
     * @return array{success: false, message: string, code: string, locked_amount: float, available: float, status_code: int}
     */
    public function lockedRefusal(Wallet $wallet): array
    {
        $locked = $this->lockedAmount((int) $wallet->customer_id);

        return [
            'success' => false,
            'message' => "KES {$locked} of the signup bonus must be staked before it can be withdrawn or moved",
            'code' => 'signup_bonus_locked',
            'locked_amount' => $locked,
            'available' => max(0.0, round((float) $wallet->balance - $locked, 2)),
            'status_code' => 400,
        ];
    }

    /**
     * The bonus as shown to the customer and the GMS.
     *
     * @return array{id: int, promotion: string, promo_code: ?string, gross_amount: float, excise_amount: float, net_amount: float, wagered: float, locked_amount: float, unlocked: bool, status: string, granted_at: ?string}
     */
    public function present(PromotionCredit $credit): array
    {
        $wagered = $credit->ledger_entry_id === null ? 0.0 : $this->wagered($credit);
        $locked = max(0.0, round((float) $credit->net_amount - $wagered, 2));

        return [
            'id' => $credit->id,
            'promotion' => $credit->promotion,
            'promo_code' => $credit->promoCode?->code,
            'gross_amount' => (float) $credit->gross_amount,
            'excise_amount' => (float) $credit->excise_amount,
            'net_amount' => (float) $credit->net_amount,
            'wagered' => $wagered,
            'locked_amount' => $locked,
            'unlocked' => $locked <= 0,
            'status' => $credit->status,
            'granted_at' => $credit->created_at?->toIso8601String(),
        ];
    }

    /**
     * Why the customer cannot get the bonus, or null when they can (subject to the code's redemptions,
     * the budget and house funds, which are checked under the lock).
     */
    private function ineligibility(Customer $customer): ?string
    {
        if (! $this->isEnabled()) {
            return 'disabled';
        }

        $promoCode = $customer->promoCode;
        if (! $promoCode) {
            return 'no_promo_code';
        }

        if (! $promoCode->isUsable()) {
            return 'promo_code_'.$promoCode->status();
        }

        return null;
    }

    private function grant(Customer $customer): ?PromotionCredit
    {
        try {
            return DB::transaction(function () use ($customer) {
                $existing = $this->creditFor($customer->id);
                if ($existing) {
                    return $existing;
                }

                $promoCode = PromoCode::lockForUpdate()->find($customer->promo_code_id);
                if (! $promoCode || ! $promoCode->isUsable()) {
                    $this->logSkip($customer, 'promo_code_unusable');

                    return null;
                }

                if ($promoCode->isFull()) {
                    $this->logSkip($customer, 'promo_code_full');

                    return null;
                }

                if ($this->identityAlreadyRewarded($customer)) {
                    $this->logSkip($customer, 'phone_already_rewarded');

                    return null;
                }

                $amounts = $this->amounts();

                if ($this->wouldExceedBudget($amounts['gross'])) {
                    $this->logSkip($customer, 'budget_cap_reached');

                    return null;
                }

                // Lock order: the player wallet, then the house wallet (the checks keep their old order).
                $wallet = Wallet::lockForUpdate()->where('customer_id', $customer->id)->first();
                $houseWallet = Wallet::lockForUpdate()->find((int) config('wallets.house_wallet_id', 1));
                if (! $houseWallet || (float) $houseWallet->balance < $amounts['gross']) {
                    Log::warning('Signup bonus not granted: the house wallet cannot fund it', ['customer_id' => $customer->id, 'house_balance' => $houseWallet?->balance, 'gross' => $amounts['gross']]);

                    return null;
                }

                if (! $wallet) {
                    $this->logSkip($customer, 'no_wallet');

                    return null;
                }

                $credit = PromotionCredit::create([
                    'customer_id' => $customer->id,
                    'wallet_id' => $wallet->id,
                    'promotion' => PromotionCredit::PROMOTION_SIGNUP_BONUS,
                    'promo_code_id' => $promoCode->id,
                    'gross_amount' => $amounts['gross'],
                    'rate' => $amounts['excise'] > 0 ? $amounts['rate'] : 0,
                    'excise_amount' => $amounts['excise'],
                    'net_amount' => $amounts['net'],
                    'status' => PromotionCredit::STATUS_GRANTED,
                ]);

                [$houseEntry, $customerEntry] = $this->ledgerService->recordPromotionCredit($credit, $houseWallet, $wallet, $amounts['gross']);

                $credit->update(['ledger_entry_id' => $customerEntry->id, 'house_ledger_entry_id' => $houseEntry->id]);

                $this->exciseDuty->chargePromotion($credit, $wallet);

                Log::info('Signup bonus granted', ['customer_id' => $customer->id, 'promotion_credit_id' => $credit->id, 'gross' => $amounts['gross'], 'net' => $amounts['net']]);

                return $credit;
            });
        } catch (UniqueConstraintViolationException) {
            return $this->creditFor($customer->id);
        }
    }

    /**
     * Another customer with the same phone number already got the bonus. phone_no is unique, but the same
     * number can be stored in different forms (07..., 2547...), so the canonical hash is compared. id_no is
     * unique too, so it cannot repeat.
     */
    private function identityAlreadyRewarded(Customer $customer): bool
    {
        if ($customer->phone_hash === null) {
            return false;
        }

        return PromotionCredit::query()
            ->where('promotion', PromotionCredit::PROMOTION_SIGNUP_BONUS)
            ->where('customer_id', '!=', $customer->id)
            ->whereHas('customer', fn ($query) => $query->where('phone_hash', $customer->phone_hash))
            ->exists();
    }

    private function wouldExceedBudget(float $gross): bool
    {
        $cap = config('promotions.signup_bonus.budget_cap');

        if ($cap === null) {
            return false;
        }

        $spent = (float) PromotionCredit::where('promotion', PromotionCredit::PROMOTION_SIGNUP_BONUS)->sum('gross_amount');

        return $spent + $gross > (float) $cap + 0.001;
    }

    private function logSkip(Customer $customer, string $reason): void
    {
        Log::info('Signup bonus not granted', ['customer_id' => $customer->id, 'reason' => $reason]);
    }

    /**
     * @param  list<string>  $values
     */
    private function placeholders(array $values): string
    {
        return implode(',', array_fill(0, max(count($values), 1), '?'));
    }
}
