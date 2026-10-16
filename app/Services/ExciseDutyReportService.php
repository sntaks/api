<?php

namespace App\Services;

use App\Models\ExciseDutyCharge;
use App\Models\ExciseDutyRemittance;
use App\Services\Concerns\BuildsFinanceQueries;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Excise duty taken from deposits and promotion credits, what has been paid to KRA and what is still owed.
 *
 * The summary and the charges list follow exclude_test like every other report. The payable
 * amount, the monthly returns and remittances always cover every charge, because that is what
 * is owed to KRA.
 */
class ExciseDutyReportService
{
    use BuildsFinanceQueries;

    public function __construct(private FinanceMasker $masker) {}

    /**
     * Excise duty charged and not yet part of a KRA remittance.
     */
    public function payable(): float
    {
        return $this->money(
            ExciseDutyCharge::charged()->whereNull('remittance_id')->sum('excise_amount')
        );
    }

    /**
     * Duty charged, reversed and remitted over the range, as totals and a series.
     *
     * @return array<string, mixed>
     */
    public function summary(FinanceDateRange $range): array
    {
        $template = fn () => [
            'deposits' => 0,
            'gross_deposits' => 0.0,
            'excise_charged' => 0.0,
            'excise_reversed' => 0.0,
            'excise_net' => 0.0,
            'excise_remitted' => 0.0,
        ];

        $series = [];
        foreach ($range->buckets() as $bucket) {
            $series[$bucket] = $template();
        }

        foreach ($this->chargeRows($range) as $row) {
            $bucket = $this->bucketOf($range, $row->day);
            $series[$bucket]['deposits'] += (int) $row->deposits;
            $series[$bucket]['gross_deposits'] += (float) $row->gross;
            $series[$bucket]['excise_charged'] += (float) $row->excise;

            if ($row->status === ExciseDutyCharge::STATUS_REVERSED) {
                $series[$bucket]['excise_reversed'] += (float) $row->excise;
            }
        }

        foreach ($this->remittanceRows($range) as $row) {
            $series[$this->bucketOf($range, $row->day)]['excise_remitted'] += (float) $row->amount;
        }

        $totals = $template();
        $list = [];
        foreach ($series as $bucket => $figures) {
            $figures['excise_net'] = $figures['excise_charged'] - $figures['excise_reversed'];

            foreach ($figures as $key => $value) {
                $totals[$key] += $value;
            }

            $list[] = ['period' => $bucket] + $this->rounded($figures);
        }

        $oldestUnremitted = ExciseDutyCharge::charged()->whereNull('remittance_id')->min('charged_at');

        return [
            'enabled' => (bool) config('finance.excise_duty.enabled'),
            'rate' => (float) config('finance.excise_duty.rate'),
            'effective_from' => config('finance.excise_duty.effective_from'),
            'totals' => $this->rounded($totals),
            'payable' => [
                'outstanding' => $this->payable(),
                'oldest_unremitted_at' => $oldestUnremitted === null ? null : $this->moment((string) $oldestUnremitted),
            ],
            'series' => $list,
            'notes' => [
                'excise_charged is every charge made in the period; excise_reversed is the part later given back; excise_net is what is owed for the period.',
                'excise_remitted is what was paid to KRA in the period, by payment date, whatever period the payment covered.',
                'payable.outstanding is every charge not yet in a remittance, from all time and all customers.',
                'Charges include duty on promotion credits (the signup bonus), which the house pays; deposits and gross_deposits count those credits too.',
            ],
        ];
    }

    /**
     * Excise duty per charge. `source` is `deposit`, `promotion` or `shop_load` (cash loaded at a bet shop);
     * only a deposit charge has a deposit.
     *
     * Filters: status (charged, reversed, remitted, unremitted), customer_id.
     *
     * @param  array<string, string>  $filters
     */
    public function charges(FinanceDateRange $range, array $filters): FinanceListing
    {
        $base = fn () => $this->chargeBase($range, $filters);

        $query = $base()
            ->leftJoin('incoming_payments as i', 'i.id', '=', 'x.deposit_id')
            ->leftJoin('customers as c', 'c.id', '=', 'x.customer_id')
            ->leftJoin('excise_duty_remittances as r', 'r.id', '=', 'x.remittance_id')
            ->selectRaw('x.*, i.trans_id, i.msisdn, c.name as customer_name, r.kra_reference')
            ->orderByDesc('x.charged_at')
            ->orderByDesc('x.id');

        $summary = function () use ($base) {
            $byStatus = $base()
                ->selectRaw('x.status, COUNT(*) as charges, SUM(x.gross_amount) as gross, SUM(x.excise_amount) as excise')
                ->groupBy('x.status')
                ->get();

            $unremitted = $base()
                ->where('x.status', ExciseDutyCharge::STATUS_CHARGED)
                ->whereNull('x.remittance_id')
                ->selectRaw('COUNT(*) as charges, COALESCE(SUM(x.excise_amount), 0) as excise')
                ->first();

            return [
                'charges' => (int) $byStatus->sum('charges'),
                'gross_deposits' => $this->money($byStatus->sum('gross')),
                'excise' => $this->money($byStatus->sum('excise')),
                'by_status' => $byStatus->mapWithKeys(fn ($row) => [$row->status => [
                    'charges' => (int) $row->charges,
                    'gross_deposits' => $this->money($row->gross),
                    'excise' => $this->money($row->excise),
                ]])->all(),
                'unremitted' => ['charges' => (int) $unremitted->charges, 'excise' => $this->money($unremitted->excise)],
            ];
        };

        return new FinanceListing(
            $query,
            fn (object $row) => [
                'id' => (int) $row->id,
                'charged_at' => $this->moment($row->charged_at),
                'source' => match (true) {
                    $row->promotion_credit_id !== null => 'promotion',
                    $row->bet_shop_load_id !== null => 'shop_load',
                    default => 'deposit',
                },
                'deposit_id' => $row->deposit_id === null ? null : (int) $row->deposit_id,
                'promotion_credit_id' => $row->promotion_credit_id === null ? null : (int) $row->promotion_credit_id,
                'bet_shop_load_id' => $row->bet_shop_load_id === null ? null : (int) $row->bet_shop_load_id,
                'trans_id' => $row->trans_id,
                'customer_id' => (int) $row->customer_id,
                'customer_name' => $row->customer_name,
                'msisdn' => $this->masker->phone($row->msisdn),
                'gross_amount' => $this->money($row->gross_amount),
                'rate' => (float) $row->rate,
                'excise_amount' => $this->money($row->excise_amount),
                'net_amount' => $this->money($row->net_amount),
                'status' => $row->status,
                'remittance_id' => $row->remittance_id === null ? null : (int) $row->remittance_id,
                'kra_reference' => $row->kra_reference,
            ],
            $summary,
            ['id', 'charged_at', 'source', 'deposit_id', 'promotion_credit_id', 'bet_shop_load_id', 'trans_id', 'customer_id', 'customer_name', 'msisdn', 'gross_amount', 'rate', 'excise_amount', 'net_amount', 'status', 'remittance_id', 'kra_reference'],
        );
    }

    /**
     * One row per calendar month the range touches: what was charged, what has been remitted, what
     * is still owed and when the return is due. Always covers every customer.
     *
     * @return list<array<string, mixed>>
     */
    public function returns(FinanceDateRange $range): array
    {
        $timezone = config('app.timezone');
        $from = $range->from->startOfMonth();
        $to = $range->to->endOfMonth();

        $rows = DB::table('excise_duty_charges')
            ->whereBetween('charged_at', [$from, $to])
            ->selectRaw('DATE(charged_at) as day, status, (remittance_id IS NOT NULL) as remitted, COUNT(*) as charges, SUM(gross_amount) as gross, SUM(excise_amount) as excise')
            ->groupByRaw('DATE(charged_at), status, (remittance_id IS NOT NULL)')
            ->get();

        $months = [];
        for ($month = $from; $month->lessThanOrEqualTo($to); $month = $month->addMonthNoOverflow()) {
            $months[$month->format('Y-m')] = [
                'period' => $month->format('Y-m'),
                'period_start' => $month->toDateString(),
                'period_end' => $month->endOfMonth()->toDateString(),
                'due_date' => $this->dueDate($month)->toDateString(),
                'charges' => 0,
                'gross_deposits' => 0.0,
                'excise_charged' => 0.0,
                'excise_reversed' => 0.0,
                'excise_due' => 0.0,
                'excise_remitted' => 0.0,
                'outstanding' => 0.0,
            ];
        }

        foreach ($rows as $row) {
            $key = CarbonImmutable::parse($row->day, $timezone)->format('Y-m');
            $months[$key]['charges'] += (int) $row->charges;
            $months[$key]['gross_deposits'] += (float) $row->gross;
            $months[$key]['excise_charged'] += (float) $row->excise;

            if ($row->status === ExciseDutyCharge::STATUS_REVERSED) {
                $months[$key]['excise_reversed'] += (float) $row->excise;
            } elseif ((bool) $row->remitted) {
                $months[$key]['excise_remitted'] += (float) $row->excise;
            }
        }

        $today = CarbonImmutable::today($timezone);
        $tolerance = (float) config('finance.reconciliation.tolerance');

        return array_values(array_map(function (array $month) use ($today, $tolerance) {
            $month['excise_due'] = $month['excise_charged'] - $month['excise_reversed'];
            $month['outstanding'] = $month['excise_due'] - $month['excise_remitted'];
            $month = $this->rounded($month);
            $month['overdue'] = $month['outstanding'] > $tolerance && $today->greaterThan(CarbonImmutable::parse($month['due_date'], config('app.timezone')));

            return $month;
        }, $months));
    }

    /**
     * Months with duty still owed after their due date, from all time.
     *
     * @return list<array{period: string, due_date: string, outstanding: float}>
     */
    public function overdueReturns(): array
    {
        $timezone = config('app.timezone');
        $today = CarbonImmutable::today($timezone);

        $rows = DB::table('excise_duty_charges')
            ->where('status', ExciseDutyCharge::STATUS_CHARGED)
            ->whereNull('remittance_id')
            ->selectRaw('DATE(charged_at) as day, SUM(excise_amount) as excise')
            ->groupByRaw('DATE(charged_at)')
            ->get();

        $months = [];
        foreach ($rows as $row) {
            $month = CarbonImmutable::parse($row->day, $timezone)->startOfMonth();
            $key = $month->format('Y-m');
            $months[$key] ??= ['period' => $key, 'due_date' => $this->dueDate($month), 'outstanding' => 0.0];
            $months[$key]['outstanding'] += (float) $row->excise;
        }

        ksort($months);
        $tolerance = (float) config('finance.reconciliation.tolerance');

        return array_values(array_map(
            fn (array $month) => ['period' => $month['period'], 'due_date' => $month['due_date']->toDateString(), 'outstanding' => $this->money($month['outstanding'])],
            array_filter($months, fn (array $month) => $month['outstanding'] > $tolerance && $today->greaterThan($month['due_date']))
        ));
    }

    /**
     * Payments to KRA whose period overlaps the range.
     *
     * Filters: status (active by default, or voided, or all).
     *
     * @param  array<string, string>  $filters
     */
    public function remittances(FinanceDateRange $range, array $filters): FinanceListing
    {
        $status = $filters['status'] ?? 'active';
        $base = fn (string $state) => DB::table('excise_duty_remittances as r')
            ->where('r.period_start', '<=', $range->to->toDateString())
            ->where('r.period_end', '>=', $range->from->toDateString())
            ->when($state === 'active', fn (Builder $query) => $query->whereNull('r.voided_at'))
            ->when($state === 'voided', fn (Builder $query) => $query->whereNotNull('r.voided_at'));

        $query = $base($status)
            ->selectRaw('r.*, (SELECT COUNT(*) FROM excise_duty_charges x WHERE x.remittance_id = r.id) as charges')
            ->orderByDesc('r.period_start')
            ->orderByDesc('r.id');

        $summary = function () use ($base) {
            $shape = fn (string $state) => $base($state)
                ->selectRaw('COUNT(*) as remittances, COALESCE(SUM(r.amount_due), 0) as due, COALESCE(SUM(r.amount_paid), 0) as paid')
                ->first();

            $active = $shape('active');
            $voided = $shape('voided');

            return [
                'active' => ['remittances' => (int) $active->remittances, 'amount_due' => $this->money($active->due), 'amount_paid' => $this->money($active->paid)],
                'voided' => ['remittances' => (int) $voided->remittances, 'amount_due' => $this->money($voided->due), 'amount_paid' => $this->money($voided->paid)],
                'payable' => $this->payable(),
            ];
        };

        return new FinanceListing(
            $query,
            fn (object $row) => [
                'id' => (int) $row->id,
                'period_start' => $row->period_start,
                'period_end' => $row->period_end,
                'charges' => (int) $row->charges,
                'amount_due' => $this->money($row->amount_due),
                'amount_paid' => $this->money($row->amount_paid),
                'difference' => $this->money((float) $row->amount_paid - (float) $row->amount_due),
                'kra_reference' => $row->kra_reference,
                'paid_at' => $this->moment($row->paid_at),
                'status' => $row->voided_at === null ? 'active' : 'voided',
                'recorded_by' => $row->recorded_by,
                'created_at' => $this->moment($row->created_at),
                'voided_at' => $this->moment($row->voided_at),
                'voided_by' => $row->voided_by,
                'void_reason' => $row->void_reason,
            ],
            $summary,
            ['id', 'period_start', 'period_end', 'charges', 'amount_due', 'amount_paid', 'difference', 'kra_reference', 'paid_at', 'status', 'recorded_by', 'created_at', 'voided_at', 'voided_by', 'void_reason'],
        );
    }

    /**
     * Record a payment to KRA. Every charge in the period not yet remitted is attached to it, and
     * amount_due is their total. Returns null when the period has nothing left to remit.
     *
     * @param  array{period_start: string, period_end: string, amount_paid: numeric-string|float|int, kra_reference: string, paid_at?: ?string}  $data
     */
    public function recordRemittance(array $data, ?string $actor): ?ExciseDutyRemittance
    {
        $timezone = config('app.timezone');
        $start = CarbonImmutable::parse($data['period_start'], $timezone)->startOfDay();
        $end = CarbonImmutable::parse($data['period_end'], $timezone)->endOfDay();

        return DB::transaction(function () use ($data, $actor, $start, $end, $timezone) {
            $charges = ExciseDutyCharge::charged()
                ->whereNull('remittance_id')
                ->whereBetween('charged_at', [$start, $end])
                ->lockForUpdate()
                ->get(['id', 'excise_amount']);

            if ($charges->isEmpty()) {
                return null;
            }

            $remittance = ExciseDutyRemittance::create([
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'amount_due' => $this->money($charges->sum('excise_amount')),
                'amount_paid' => $data['amount_paid'],
                'kra_reference' => $data['kra_reference'],
                'paid_at' => isset($data['paid_at']) ? CarbonImmutable::parse($data['paid_at'], $timezone) : now(),
                'recorded_by' => $actor,
            ]);

            ExciseDutyCharge::whereKey($charges->modelKeys())->update(['remittance_id' => $remittance->id]);

            return $remittance->loadCount('charges');
        });
    }

    /**
     * Void a remittance entered in error. Its charges become unremitted again. Returns null when
     * it was already voided, so a repeat call changes nothing.
     */
    public function voidRemittance(int $id, string $reason, ?string $actor): ?ExciseDutyRemittance
    {
        return DB::transaction(function () use ($id, $reason, $actor) {
            $remittance = ExciseDutyRemittance::lockForUpdate()->findOrFail($id);

            if ($remittance->isVoided()) {
                return null;
            }

            ExciseDutyCharge::where('remittance_id', $remittance->id)->update(['remittance_id' => null]);

            $remittance->update([
                'voided_at' => now(),
                'voided_by' => $actor,
                'void_reason' => $reason,
            ]);

            return $remittance->loadCount('charges');
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function presentRemittance(ExciseDutyRemittance $remittance): array
    {
        return [
            'id' => $remittance->id,
            'period_start' => $remittance->period_start->toDateString(),
            'period_end' => $remittance->period_end->toDateString(),
            'charges' => (int) ($remittance->charges_count ?? $remittance->charges()->count()),
            'amount_due' => (float) $remittance->amount_due,
            'amount_paid' => (float) $remittance->amount_paid,
            'difference' => $this->money((float) $remittance->amount_paid - (float) $remittance->amount_due),
            'kra_reference' => $remittance->kra_reference,
            'paid_at' => $remittance->paid_at?->toIso8601String(),
            'status' => $remittance->isVoided() ? 'voided' : 'active',
            'recorded_by' => $remittance->recorded_by,
            'voided_at' => $remittance->voided_at?->toIso8601String(),
            'voided_by' => $remittance->voided_by,
            'void_reason' => $remittance->void_reason,
        ];
    }

    /**
     * Charged and reversed duty per day and status in the range, for the cash flow and summary.
     *
     * @return iterable<object{day: string, status: string, deposits: int, gross: string, excise: string}>
     */
    public function chargeRows(FinanceDateRange $range): iterable
    {
        return $this->chargeBase($range, [])
            ->selectRaw('DATE(x.charged_at) as day, x.status, COUNT(*) as deposits, SUM(x.gross_amount) as gross, SUM(x.excise_amount) as excise')
            ->groupByRaw('DATE(x.charged_at), x.status')
            ->get();
    }

    /**
     * Amounts paid to KRA per payment day in the range, leaving out voided remittances.
     *
     * @return iterable<object{day: string, amount: string}>
     */
    public function remittanceRows(FinanceDateRange $range): iterable
    {
        return DB::table('excise_duty_remittances')
            ->whereNull('voided_at')
            ->whereBetween('paid_at', [$range->from, $range->to])
            ->selectRaw('DATE(paid_at) as day, SUM(amount_paid) as amount')
            ->groupByRaw('DATE(paid_at)')
            ->get();
    }

    /**
     * @param  array<string, string>  $filters
     */
    private function chargeBase(FinanceDateRange $range, array $filters): Builder
    {
        $excluded = $this->excludedCustomerIds($range);
        $status = $filters['status'] ?? null;

        return DB::table('excise_duty_charges as x')
            ->whereBetween('x.charged_at', [$range->from, $range->to])
            ->when($excluded !== [], fn (Builder $query) => $query->whereNotIn('x.customer_id', $excluded))
            ->when(in_array($status, [ExciseDutyCharge::STATUS_CHARGED, ExciseDutyCharge::STATUS_REVERSED], true), fn (Builder $query) => $query->where('x.status', $status))
            ->when($status === 'remitted', fn (Builder $query) => $query->whereNotNull('x.remittance_id'))
            ->when($status === 'unremitted', fn (Builder $query) => $query->where('x.status', ExciseDutyCharge::STATUS_CHARGED)->whereNull('x.remittance_id'))
            ->when(isset($filters['customer_id']), fn (Builder $query) => $query->where('x.customer_id', (int) $filters['customer_id']));
    }

    /**
     * The return and payment for a month are due on the filing day of the next month.
     */
    private function dueDate(CarbonImmutable $month): CarbonImmutable
    {
        $nextMonth = $month->startOfMonth()->addMonthNoOverflow();

        return $nextMonth->setDay(min((int) config('finance.excise_duty.filing_day', 20), $nextMonth->daysInMonth));
    }

    private function bucketOf(FinanceDateRange $range, string $day): string
    {
        return $range->bucketFor(CarbonImmutable::parse($day, config('app.timezone')));
    }

    /**
     * Rounds every float to the cent and leaves other values alone.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function rounded(array $values): array
    {
        return array_map(fn ($value) => is_float($value) ? round($value, 2) : $value, $values);
    }
}
