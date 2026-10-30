<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\DepositLimit;
use Illuminate\Support\Facades\DB;

/**
 * A player sets, cuts, raises or removes one of their deposit limits (D13). A first limit or a cut applies at
 * once. A raise or a removal waits limit_raise_delay_hours (24), so a limit cannot be lifted in the heat of
 * the moment.
 */
class DepositLimitService
{
    public function set(Customer $customer, string $period, ?int $amount, ?string $actor): DepositLimit
    {
        return DB::transaction(function () use ($customer, $period, $amount, $actor) {
            $limit = DepositLimit::where('customer_id', $customer->id)->where('period', $period)->lockForUpdate()->first()
                ?? new DepositLimit(['customer_id' => $customer->id, 'period' => $period]);

            $current = $limit->exists ? $limit->effectiveAmount() : null;
            $new = $amount === null ? null : (float) $amount;
            $appliesNow = $new === $current || ($new !== null && ($current === null || $new < $current));

            $limit->fill($appliesNow
                ? ['amount' => $new, 'pending_amount' => null, 'has_pending_change' => false, 'pending_from' => null]
                : [
                    'amount' => $current,
                    'pending_amount' => $new,
                    'has_pending_change' => true,
                    'pending_from' => now()->addHours((int) config('responsible_gambling.limit_raise_delay_hours'))->startOfSecond(),
                ]);
            $limit->actor = $actor;
            $limit->save();

            return $limit;
        });
    }

    /**
     * @return array{period: string, amount: float|null, pending_amount: float|null, pending_from: string|null}
     */
    public function present(DepositLimit $limit): array
    {
        return [
            'period' => $limit->period,
            'amount' => $limit->amount === null ? null : (float) $limit->amount,
            'pending_amount' => $limit->has_pending_change && $limit->pending_amount !== null ? (float) $limit->pending_amount : null,
            'pending_from' => $limit->has_pending_change ? $limit->pending_from?->toIso8601String() : null,
        ];
    }
}
