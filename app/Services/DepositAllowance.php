<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\DepositLimit;
use App\Models\LedgerEntry;
use App\Models\StkPush;
use Illuminate\Support\Collection;

/**
 * How much more a player may deposit right now under their own limits (D13). Each limit counts a rolling
 * window: paybill and STK deposits and shop loads credited to the player's wallet, net of reversals, plus STK
 * prompts sent in the last few minutes whose money has not arrived yet (a push stops counting once its deposit
 * settles it, so the money is never counted twice).
 */
class DepositAllowance
{
    /** Ledger entry types that bring a player's own money into their wallet (each with its _reversal). */
    private const DEPOSIT_TYPES = ['deposit', 'shop_load'];

    /**
     * Shillings the player can still deposit, or null when no limit applies.
     */
    public function remaining(Customer $customer): ?float
    {
        $remaining = null;

        foreach ($this->limits($customer) as $limit) {
            $left = $this->remainingUnder($customer, $limit);

            if ($left !== null) {
                $remaining = $remaining === null ? $left : min($remaining, $left);
            }
        }

        return $remaining;
    }

    /**
     * Shillings left under one limit, or null when that limit is not in force.
     */
    public function remainingUnder(Customer $customer, DepositLimit $limit): ?float
    {
        $amount = $limit->effectiveAmount();

        if ($amount === null) {
            return null;
        }

        $usedCents = $this->depositedCentsWithin($customer, $limit->hours()) + $this->pendingStkCents($customer);

        return max(0, (int) round($amount * 100) - $usedCents) / 100;
    }

    /**
     * @return Collection<int, DepositLimit>
     */
    public function limits(Customer $customer): Collection
    {
        return DepositLimit::where('customer_id', $customer->id)
            ->orderByRaw("FIELD(period, 'day', 'week', 'month')")
            ->get();
    }

    private function depositedCentsWithin(Customer $customer, int $hours): int
    {
        $walletId = $customer->wallet()->value('id');

        if ($walletId === null) {
            return 0;
        }

        $types = array_merge(self::DEPOSIT_TYPES, array_map(fn (string $type) => $type.'_reversal', self::DEPOSIT_TYPES));

        $net = LedgerEntry::query()
            ->where('wallet_type', LedgerEntry::WALLET_TYPE_WALLET)
            ->where('wallet_id', $walletId)
            ->whereIn('entry_type', $types)
            ->where('created_at', '>', now()->subHours($hours))
            ->selectRaw('COALESCE(SUM(credit - debit), 0) as net')
            ->value('net');

        return max(0, (int) round((float) $net * 100));
    }

    private function pendingStkCents(Customer $customer): int
    {
        $sum = StkPush::where('customer_id', $customer->id)
            ->whereNull('settled_at')
            ->where('created_at', '>', now()->subMinutes((int) config('responsible_gambling.stk_pending_minutes')))
            ->sum('amount');

        return (int) round((float) $sum * 100);
    }
}
