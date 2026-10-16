<?php

namespace App\Services;

use App\Models\BetShopLoad;
use App\Models\Deposit;
use App\Models\ExciseDutyCharge;
use App\Models\PromotionCredit;
use App\Models\Wallet;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Excise duty on M-Pesa wallet deposits and on promotion credits, using the rules in config/finance.php.
 * The wallet is credited with the gross amount first, then this takes the duty off with its own ledger entry.
 */
class ExciseDutyService
{
    public const KIND_WALLET_DEPOSIT = 'wallet_deposit';

    /** Cash a player paid at a bet shop and had loaded to their wallet. */
    public const KIND_SHOP_DEPOSIT = 'shop_deposit';

    public function __construct(private LedgerService $ledgerService) {}

    public function isEnabled(): bool
    {
        return (bool) config('finance.excise_duty.enabled') && $this->rate() > 0;
    }

    public function rate(): float
    {
        return (float) config('finance.excise_duty.rate');
    }

    /**
     * Whether a deposit of this kind is charged: the feature is on, the kind is listed in
     * `applies_to`, the amount is positive and the deposit was made on or after `effective_from`.
     */
    public function isApplicable(Deposit $deposit, string $depositKind): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        if (! in_array($depositKind, config('finance.excise_duty.applies_to', []), true)) {
            return false;
        }

        if ((float) $deposit->trans_amount <= 0) {
            return false;
        }

        $effectiveFrom = config('finance.excise_duty.effective_from');

        return blank($effectiveFrom)
            || $this->depositTime($deposit)->gte(Carbon::parse($effectiveFrom, config('app.timezone'))->startOfDay());
    }

    /**
     * Split a gross deposit into duty and what the customer keeps. The duty is rounded half up to the cent.
     *
     * @return array{gross: float, rate: float, excise: float, net: float}
     */
    public function calculate(float $gross, ?float $rate = null): array
    {
        $rate ??= $this->rate();

        // Round to 6 places first so float noise (10.10 * 0.05 = 0.50499...) cannot push a half cent down.
        $excise = round(round($gross * $rate, 6), 2);

        return [
            'gross' => round($gross, 2),
            'rate' => $rate,
            'excise' => $excise,
            'net' => round($gross - $excise, 2),
        ];
    }

    /**
     * Whether duty is charged on something credited now: the feature is on and today is on or after
     * `effective_from`. Used for promotion credits, which have no deposit kind.
     */
    public function appliesNow(): bool
    {
        $effectiveFrom = config('finance.excise_duty.effective_from');

        return $this->isEnabled()
            && (blank($effectiveFrom) || now()->gte(Carbon::parse($effectiveFrom, config('app.timezone'))->startOfDay()));
    }

    /**
     * The gross amount that leaves exactly $net after duty, split like calculate(). With duty off the gross
     * is the net. The first guess is net / (1 - rate); rounding can leave it a cent out, so it is nudged.
     *
     * @return array{gross: float, rate: float, excise: float, net: float}
     */
    public function grossUpForNet(float $net, ?float $rate = null): array
    {
        $rate ??= $this->appliesNow() ? $this->rate() : 0.0;

        if ($rate <= 0) {
            return $this->calculate($net, 0.0);
        }

        $gross = round($net / (1 - $rate), 2);

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $amounts = $this->calculate($gross, $rate);

            if (abs($amounts['net'] - $net) < 0.001) {
                return $amounts;
            }

            $gross = round($gross + ($amounts['net'] < $net ? 0.01 : -0.01), 2);
        }

        return $this->calculate($gross, $rate);
    }

    /**
     * Charge duty on a promotion credit already credited to the wallet. Call inside the caller's
     * transaction, with the wallet row locked.
     */
    public function chargePromotion(PromotionCredit $credit, Wallet $wallet): ?ExciseDutyCharge
    {
        if ((float) $credit->excise_amount <= 0) {
            return null;
        }

        $entry = $this->ledgerService->recordPromotionExciseDuty($credit, $wallet, (float) $credit->excise_amount, (float) $credit->rate);

        return ExciseDutyCharge::create([
            'deposit_id' => null,
            'promotion_credit_id' => $credit->id,
            'customer_id' => $wallet->customer_id,
            'wallet_id' => $wallet->id,
            'ledger_entry_id' => $entry->id,
            'gross_amount' => $credit->gross_amount,
            'rate' => $credit->rate,
            'excise_amount' => $credit->excise_amount,
            'net_amount' => $credit->net_amount,
            'status' => ExciseDutyCharge::STATUS_CHARGED,
            'charged_at' => now(),
        ]);
    }

    /**
     * Charge duty on cash loaded at a shop, already credited to the wallet, when the feature is on, the
     * `shop_deposit` kind is listed in `applies_to` and today is on or after `effective_from`. Call inside
     * the caller's transaction, with the wallet row locked.
     */
    public function chargeShopLoad(BetShopLoad $load, Wallet $wallet): ?ExciseDutyCharge
    {
        if (! $this->appliesNow() || ! in_array(self::KIND_SHOP_DEPOSIT, config('finance.excise_duty.applies_to', []), true)) {
            return null;
        }

        $amounts = $this->calculate((float) $load->gross_amount);
        if ($amounts['excise'] <= 0) {
            return null;
        }

        $entry = $this->ledgerService->recordShopLoadExciseDuty($load, $wallet, $amounts['excise'], $amounts['rate']);

        return ExciseDutyCharge::create([
            'deposit_id' => null,
            'bet_shop_load_id' => $load->id,
            'customer_id' => $wallet->customer_id,
            'wallet_id' => $wallet->id,
            'ledger_entry_id' => $entry->id,
            'gross_amount' => $amounts['gross'],
            'rate' => $amounts['rate'],
            'excise_amount' => $amounts['excise'],
            'net_amount' => $amounts['net'],
            'status' => ExciseDutyCharge::STATUS_CHARGED,
            'charged_at' => now(),
        ]);
    }

    /**
     * Charge duty on a deposit already credited to the wallet, when it applies. Returns the
     * existing charge if the deposit was charged before. Call inside the caller's transaction,
     * with the wallet row locked.
     */
    public function chargeIfApplicable(Deposit $deposit, Wallet $wallet, string $depositKind): ?ExciseDutyCharge
    {
        $existing = ExciseDutyCharge::where('deposit_id', $deposit->id)->first();
        if ($existing) {
            return $existing;
        }

        if (! $this->isApplicable($deposit, $depositKind)) {
            return null;
        }

        $amounts = $this->calculate((float) $deposit->trans_amount);
        if ($amounts['excise'] <= 0) {
            return null;
        }

        $entry = $this->ledgerService->recordExciseDuty($deposit, $wallet, $amounts['excise'], $amounts['rate']);

        return ExciseDutyCharge::create([
            'deposit_id' => $deposit->id,
            'customer_id' => $wallet->customer_id,
            'wallet_id' => $wallet->id,
            'ledger_entry_id' => $entry->id,
            'gross_amount' => $amounts['gross'],
            'rate' => $amounts['rate'],
            'excise_amount' => $amounts['excise'],
            'net_amount' => $amounts['net'],
            'status' => ExciseDutyCharge::STATUS_CHARGED,
            'charged_at' => $this->depositTime($deposit),
        ]);
    }

    /**
     * Give the duty back to the customer when their deposit is refunded or reversed. A charge that
     * is already part of a KRA remittance, or already reversed, is left alone and false is returned.
     */
    public function reverse(ExciseDutyCharge $charge): bool
    {
        return DB::transaction(function () use ($charge) {
            $charge = ExciseDutyCharge::lockForUpdate()->find($charge->id);

            if ($charge->status !== ExciseDutyCharge::STATUS_CHARGED || $charge->isRemitted()) {
                return false;
            }

            Wallet::lockForUpdate()->find($charge->wallet_id);

            $this->ledgerService->reverseEntry($charge->ledgerEntry);

            $charge->update(['status' => ExciseDutyCharge::STATUS_REVERSED]);

            return true;
        });
    }

    /**
     * When the customer paid: the M-Pesa transaction time, or when the callback arrived if that cannot be read.
     */
    private function depositTime(Deposit $deposit): Carbon
    {
        try {
            if (filled($deposit->trans_time)) {
                return Carbon::parse($deposit->trans_time, config('app.timezone'));
            }
        } catch (Throwable) {
            // Fall through to the time the callback was recorded.
        }

        return $deposit->created_at ? Carbon::parse($deposit->created_at) : now();
    }
}
