<?php

namespace App\Services;

use App\Models\Deposit;
use App\Services\Concerns\BuildsFinanceQueries;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Drill-downs into money coming in and going out through M-Pesa, and what customers bought.
 */
class FinancePaymentReportService
{
    use BuildsFinanceQueries;

    private const DEPOSIT_KIND = "CASE WHEN h.id IS NOT NULL THEN 'house_funding' WHEN sf.id IS NOT NULL THEN 'shop_float' WHEN i.status IN (0, 4) THEN 'unmatched' WHEN p.purchase_type IS NOT NULL THEN p.purchase_type ELSE 'wallet_deposit' END";

    private const DEPOSIT_CUSTOMER = 'COALESCE(p.customer_id, l.customer_id)';

    private const WITHDRAWAL_STATUS = "CASE o.disburse WHEN 2 THEN 'paid' WHEN 3 THEN 'failed' ELSE 'pending' END";

    public function __construct(private FinanceMasker $masker) {}

    /**
     * Payments received, with who they were for and what they bought.
     *
     * Filters: status (0 unmatched, 1 pending, 2 processed, 4 refunded), kind, customer_id.
     *
     * @param  array<string, string>  $filters
     */
    public function deposits(FinanceDateRange $range, array $filters): FinanceListing
    {
        $base = fn () => $this->depositBase($range, $filters);

        $query = $base()
            ->selectRaw('i.id, i.trans_id, i.trans_time, i.trans_amount as amount, i.status, i.msisdn, i.bill_ref_no, i.created_at, '
                .self::DEPOSIT_KIND.' as kind, '.self::DEPOSIT_CUSTOMER.' as customer_id, c.name as customer_name')
            ->orderByDesc('i.id');

        $summary = function () use ($base) {
            $byKind = $base()
                ->selectRaw(self::DEPOSIT_KIND.' as kind, COUNT(*) as payments, SUM(i.trans_amount) as amount')
                ->groupByRaw(self::DEPOSIT_KIND)
                ->get();

            $byStatus = $base()
                ->selectRaw('i.status, COUNT(*) as payments, SUM(i.trans_amount) as amount')
                ->groupBy('i.status')
                ->get();

            $topDepositors = $base()
                ->whereRaw(self::DEPOSIT_CUSTOMER.' IS NOT NULL')
                ->selectRaw(self::DEPOSIT_CUSTOMER.' as customer_id, c.name as customer_name, COUNT(*) as payments, SUM(i.trans_amount) as amount')
                ->groupByRaw(self::DEPOSIT_CUSTOMER.', c.name')
                ->orderByDesc('amount')
                ->limit(10)
                ->get();

            return [
                'payments' => (int) $byKind->sum('payments'),
                'amount' => $this->money($byKind->sum('amount')),
                'by_kind' => $byKind->mapWithKeys(fn ($row) => [$row->kind => ['payments' => (int) $row->payments, 'amount' => $this->money($row->amount)]])->all(),
                'by_status' => $byStatus->mapWithKeys(fn ($row) => [$this->depositStatus((int) $row->status) => ['payments' => (int) $row->payments, 'amount' => $this->money($row->amount)]])->all(),
                'top_depositors' => $topDepositors->map(fn ($row) => [
                    'customer_id' => (int) $row->customer_id,
                    'customer_name' => $row->customer_name,
                    'payments' => (int) $row->payments,
                    'amount' => $this->money($row->amount),
                ])->all(),
            ];
        };

        return new FinanceListing(
            $query,
            fn (object $row) => [
                'id' => (int) $row->id,
                'trans_id' => $row->trans_id,
                'amount' => $this->money($row->amount),
                'kind' => $row->kind,
                'status' => $this->depositStatus((int) $row->status),
                'customer_id' => $row->customer_id === null ? null : (int) $row->customer_id,
                'customer_name' => $row->customer_name,
                'msisdn' => $this->masker->phone($row->msisdn),
                'bill_ref_no' => $this->masker->phone($row->bill_ref_no),
                'created_at' => $this->moment($row->created_at),
            ],
            $summary,
            ['id', 'trans_id', 'amount', 'kind', 'status', 'customer_id', 'customer_name', 'msisdn', 'bill_ref_no', 'created_at'],
        );
    }

    /**
     * Payouts to customers by M-Pesa, with failures and how long pending ones have waited.
     *
     * Filters: status (pending, paid, failed), customer_id.
     *
     * @param  array<string, string>  $filters
     */
    public function withdrawals(FinanceDateRange $range, array $filters): FinanceListing
    {
        $base = fn () => $this->withdrawalBase($range, $filters);

        $query = $base()
            ->selectRaw('o.id, o.transaction_id, o.amount, o.disburse, o.receipt, o.error_message, o.created_at, '
                .self::WITHDRAWAL_STATUS.' as status, w.customer_id, c.name as customer_name')
            ->orderByDesc('o.id');

        $summary = function () use ($base) {
            $byStatus = $base()
                ->selectRaw(self::WITHDRAWAL_STATUS.' as status, COUNT(*) as payments, SUM(o.amount) as amount')
                ->groupByRaw(self::WITHDRAWAL_STATUS)
                ->get();

            $reasons = $base()
                ->where('o.disburse', 3)
                ->selectRaw("COALESCE(o.error_message, 'Unknown') as reason, COUNT(*) as payments, SUM(o.amount) as amount")
                ->groupByRaw("COALESCE(o.error_message, 'Unknown')")
                ->orderByDesc('payments')
                ->limit(10)
                ->get();

            $stuckHours = (int) config('finance.reconciliation.stuck_withdrawal_hours');
            $pending = $base()->where('o.disburse', 1);
            $oldest = (clone $pending)->min('o.created_at');
            $stuck = (clone $pending)->where('o.created_at', '<=', now()->subHours($stuckHours))
                ->selectRaw('COUNT(*) as payments, COALESCE(SUM(o.amount), 0) as amount')->first();

            return [
                'payments' => (int) $byStatus->sum('payments'),
                'amount' => $this->money($byStatus->sum('amount')),
                'by_status' => $byStatus->mapWithKeys(fn ($row) => [$row->status => ['payments' => (int) $row->payments, 'amount' => $this->money($row->amount)]])->all(),
                'failure_reasons' => $reasons->map(fn ($row) => [
                    'reason' => $row->reason,
                    'payments' => (int) $row->payments,
                    'amount' => $this->money($row->amount),
                ])->all(),
                'oldest_pending_hours' => $oldest === null ? null : (int) CarbonImmutable::parse($oldest, config('app.timezone'))->diffInHours(now()),
                'stuck_pending' => [
                    'threshold_hours' => $stuckHours,
                    'payments' => (int) $stuck->payments,
                    'amount' => $this->money($stuck->amount),
                ],
            ];
        };

        return new FinanceListing(
            $query,
            fn (object $row) => [
                'id' => (int) $row->id,
                'transaction_id' => $row->transaction_id === null ? null : (int) $row->transaction_id,
                'amount' => $this->money($row->amount),
                'status' => $row->status,
                'receipt' => $row->receipt,
                'error_message' => $row->error_message,
                'customer_id' => $row->customer_id === null ? null : (int) $row->customer_id,
                'customer_name' => $row->customer_name,
                'age_hours' => (int) $row->disburse === 1 ? (int) CarbonImmutable::parse($row->created_at, config('app.timezone'))->diffInHours(now()) : null,
                'created_at' => $this->moment($row->created_at),
            ],
            $summary,
            ['id', 'transaction_id', 'amount', 'status', 'receipt', 'error_message', 'customer_id', 'customer_name', 'age_hours', 'created_at'],
        );
    }

    /**
     * What customers bought: wallet loads, gifts and emojis, with the cash paid and the value given.
     *
     * Filters: type (load, gift, emoji), customer_id. `exclude_test` also drops purchases flagged as test.
     *
     * @param  array<string, string>  $filters
     */
    public function purchases(FinanceDateRange $range, array $filters): FinanceListing
    {
        $base = fn () => $this->purchaseBase($range, $filters);

        $query = $base()
            ->selectRaw('p.id, p.customer_id, c.name as customer_name, p.purchase_type as type, p.amount, p.value, p.deposit_id, p.test, p.created_at')
            ->orderByDesc('p.id');

        $summary = function () use ($base, $range) {
            $byType = $base()
                ->selectRaw('p.purchase_type as type, COUNT(*) as purchases, SUM(p.amount) as amount, COALESCE(SUM(p.value), 0) as value')
                ->groupBy('p.purchase_type')
                ->get();

            $series = [];
            foreach ($range->buckets() as $bucket) {
                $series[$bucket] = ['load' => 0.0, 'gift' => 0.0, 'emoji' => 0.0, 'other' => 0.0, 'total' => 0.0];
            }

            $daily = $base()
                ->selectRaw('DATE(p.created_at) as day, p.purchase_type as type, SUM(p.amount) as amount')
                ->groupBy('day', 'type')
                ->get();

            foreach ($daily as $row) {
                $bucket = $range->bucketFor(CarbonImmutable::parse($row->day, config('app.timezone')));
                $line = in_array($row->type, ['load', 'gift', 'emoji'], true) ? $row->type : 'other';
                $series[$bucket][$line] += (float) $row->amount;
                $series[$bucket]['total'] += (float) $row->amount;
            }

            return [
                'purchases' => (int) $byType->sum('purchases'),
                'amount' => $this->money($byType->sum('amount')),
                'by_type' => $byType->mapWithKeys(fn ($row) => [$row->type => [
                    'purchases' => (int) $row->purchases,
                    'amount' => $this->money($row->amount),
                    'value' => $this->money($row->value),
                ]])->all(),
                'series' => collect($series)->map(fn (array $figures, string $period) => ['period' => $period] + array_map(fn ($value) => $this->money($value), $figures))->values()->all(),
            ];
        };

        return new FinanceListing(
            $query,
            fn (object $row) => [
                'id' => (int) $row->id,
                'customer_id' => (int) $row->customer_id,
                'customer_name' => $row->customer_name,
                'type' => $row->type,
                'amount' => $this->money($row->amount),
                'value' => $this->money($row->value),
                'deposit_id' => $row->deposit_id === null ? null : (int) $row->deposit_id,
                'is_test' => (bool) $row->test,
                'created_at' => $this->moment($row->created_at),
            ],
            $summary,
            ['id', 'customer_id', 'customer_name', 'type', 'amount', 'value', 'deposit_id', 'is_test', 'created_at'],
        );
    }

    /**
     * @param  array<string, string>  $filters
     */
    private function depositBase(FinanceDateRange $range, array $filters): Builder
    {
        $excluded = $this->excludedCustomerIds($range);
        $customer = self::DEPOSIT_CUSTOMER;

        return DB::table('incoming_payments as i')
            ->leftJoin('purchases as p', 'p.deposit_id', '=', 'i.id')
            ->leftJoin('ledger_entries as l', function ($join) {
                $join->on('l.referenceable_id', '=', 'i.id')
                    ->where('l.referenceable_type', Deposit::class)
                    ->where('l.entry_type', 'deposit');
            })
            ->leftJoin('house_fundings as h', fn ($join) => $join->on('h.deposit_id', '=', 'i.id')->whereNull('h.voided_at'))
            ->leftJoin('bet_shop_float_purchases as sf', fn ($join) => $join->on('sf.deposit_id', '=', 'i.id')->whereNull('sf.voided_at'))
            ->leftJoin('customers as c', fn ($join) => $join->whereRaw("c.id = {$customer}"))
            ->whereBetween('i.created_at', [$range->from, $range->to])
            ->when($excluded !== [], fn (Builder $query) => $query->whereRaw(
                "({$customer} IS NULL OR {$customer} NOT IN (".$this->placeholders($excluded).'))',
                $excluded
            ))
            ->when(isset($filters['status']), fn (Builder $query) => $query->where('i.status', (int) $filters['status']))
            ->when(isset($filters['kind']), fn (Builder $query) => $query->whereRaw(self::DEPOSIT_KIND.' = ?', [$filters['kind']]))
            ->when(isset($filters['customer_id']), fn (Builder $query) => $query->whereRaw("{$customer} = ?", [(int) $filters['customer_id']]));
    }

    /**
     * @param  array<string, string>  $filters
     */
    private function withdrawalBase(FinanceDateRange $range, array $filters): Builder
    {
        $excluded = $this->excludedCustomerIds($range);

        return DB::table('outgoing_payments as o')
            ->leftJoin('transactions as t', 't.id', '=', 'o.transaction_id')
            ->leftJoin('wallets as w', 'w.id', '=', 't.wallet_id')
            ->leftJoin('customers as c', 'c.id', '=', 'w.customer_id')
            ->whereBetween('o.created_at', [$range->from, $range->to])
            ->when($excluded !== [], fn (Builder $query) => $query->whereRaw(
                '(w.customer_id IS NULL OR w.customer_id NOT IN ('.$this->placeholders($excluded).'))',
                $excluded
            ))
            ->when(isset($filters['status']), fn (Builder $query) => $query->whereRaw(self::WITHDRAWAL_STATUS.' = ?', [$filters['status']]))
            ->when(isset($filters['customer_id']), fn (Builder $query) => $query->where('w.customer_id', (int) $filters['customer_id']));
    }

    /**
     * @param  array<string, string>  $filters
     */
    private function purchaseBase(FinanceDateRange $range, array $filters): Builder
    {
        $excluded = $this->excludedCustomerIds($range);

        return DB::table('purchases as p')
            ->leftJoin('customers as c', 'c.id', '=', 'p.customer_id')
            ->whereBetween('p.created_at', [$range->from, $range->to])
            ->when($range->excludeTest, fn (Builder $query) => $query->where('p.test', 0))
            ->when($excluded !== [], fn (Builder $query) => $query->whereNotIn('p.customer_id', $excluded))
            ->when(isset($filters['type']), fn (Builder $query) => $query->where('p.purchase_type', $filters['type']))
            ->when(isset($filters['customer_id']), fn (Builder $query) => $query->where('p.customer_id', (int) $filters['customer_id']));
    }

    private function depositStatus(int $status): string
    {
        return match ($status) {
            0 => 'unmatched',
            2 => 'processed',
            4 => 'refunded',
            default => 'pending',
        };
    }
}
