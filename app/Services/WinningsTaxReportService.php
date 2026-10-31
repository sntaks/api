<?php

namespace App\Services;

use App\Models\WinningsTaxCharge;
use App\Models\WinningsTaxRemittance;
use App\Services\Concerns\BuildsFinanceQueries;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Winnings tax withheld from house-banked wins, what has been paid to KRA and what is still owed.
 *
 * Rows are signed: a charge is positive and the reversal of a taxed settlement is a negative credit row, so
 * what is owed is the plain sum of every row not yet in a remittance. The summary and the charges list follow
 * exclude_test like every other report; the payable amount, the monthly returns and remittances always cover
 * every row, because that is what is owed to KRA.
 */
class WinningsTaxReportService
{
    use BuildsFinanceQueries;

    /**
     * Winnings tax charged, less credits, not yet part of a KRA remittance. Negative when credits exceed charges.
     */
    public function payable(): float
    {
        return $this->money(WinningsTaxCharge::whereNull('remittance_id')->sum('amount'));
    }

    /**
     * Tax charged, reversed and remitted over the range, as totals and a series.
     *
     * @return array<string, mixed>
     */
    public function summary(FinanceDateRange $range): array
    {
        $template = fn () => [
            'charges' => 0,
            'tax_charged' => 0.0,
            'tax_reversed' => 0.0,
            'tax_net' => 0.0,
            'tax_remitted' => 0.0,
        ];

        $series = [];
        foreach ($range->buckets() as $bucket) {
            $series[$bucket] = $template();
        }

        foreach ($this->chargeRows($range) as $row) {
            $bucket = $this->bucketOf($range, $row->day);

            if ($row->kind === WinningsTaxCharge::KIND_REVERSAL) {
                $series[$bucket]['tax_reversed'] += -(float) $row->amount;
            } else {
                $series[$bucket]['charges'] += (int) $row->rows;
                $series[$bucket]['tax_charged'] += (float) $row->amount;
            }
        }

        foreach ($this->remittanceRows($range) as $row) {
            $series[$this->bucketOf($range, $row->day)]['tax_remitted'] += (float) $row->amount;
        }

        $totals = $template();
        $list = [];
        foreach ($series as $bucket => $figures) {
            $figures['tax_net'] = $figures['tax_charged'] - $figures['tax_reversed'];

            foreach ($figures as $key => $value) {
                $totals[$key] += $value;
            }

            $list[] = ['period' => $bucket] + $this->rounded($figures);
        }

        $oldestUnremitted = WinningsTaxCharge::whereNull('remittance_id')->where('kind', WinningsTaxCharge::KIND_CHARGE)->min('charged_at');

        return [
            'totals' => $this->rounded($totals),
            'payable' => [
                'outstanding' => $this->payable(),
                'oldest_unremitted_at' => $oldestUnremitted === null ? null : $this->moment((string) $oldestUnremitted),
            ],
            'series' => $list,
            'notes' => [
                'tax_charged is the winnings tax withheld in the period; tax_reversed is tax given back because a taxed settlement was reversed in the period; tax_net is what the period adds to what is owed.',
                'A reversal of tax already paid to KRA is a credit: it lowers the next return instead of changing the one already filed.',
                'tax_remitted is what was paid to KRA in the period, by payment date, whatever period the payment covered.',
                'payable.outstanding is every charge and credit not yet in a remittance, from all time and all customers.',
                'The rate is per product (products.winnings_tax_rate) and the base is the profit: payout plus bonus less the cash stake, or the whole payout and bonus for a free bet.',
            ],
        ];
    }

    /**
     * Winnings tax per row: a charge on a taxed settlement, or the credit of its reversal.
     *
     * Filters: kind (charge, reversal), status (remitted, unremitted), customer_id, product (code).
     *
     * @param  array<string, string>  $filters
     */
    public function charges(FinanceDateRange $range, array $filters): FinanceListing
    {
        $base = fn () => $this->chargeBase($range, $filters);

        $query = $base()
            ->join('wagers as w', 'w.id', '=', 'x.wager_id')
            ->join('wager_settlements as s', 's.id', '=', 'x.wager_settlement_id')
            ->join('products as p', 'p.id', '=', 'x.product_id')
            ->leftJoin('customers as c', 'c.id', '=', 'x.customer_id')
            ->leftJoin('winnings_tax_remittances as r', 'r.id', '=', 'x.remittance_id')
            ->selectRaw('x.*, w.reference as wager_reference, s.version as settlement_version, p.code as product_code, c.name as customer_name, r.kra_reference')
            ->orderByDesc('x.charged_at')
            ->orderByDesc('x.id');

        $summary = function () use ($base) {
            $byKind = $base()
                ->selectRaw('x.kind, COUNT(*) as rows_count, SUM(x.tax_base) as base, SUM(x.amount) as amount')
                ->groupBy('x.kind')
                ->get();

            $unremitted = $base()
                ->whereNull('x.remittance_id')
                ->selectRaw('COUNT(*) as rows_count, COALESCE(SUM(x.amount), 0) as amount')
                ->first();

            return [
                'rows' => (int) $byKind->sum('rows_count'),
                'tax_net' => $this->money($byKind->sum('amount')),
                'by_kind' => $byKind->mapWithKeys(fn ($row) => [$row->kind => [
                    'rows' => (int) $row->rows_count,
                    'tax_base' => $this->money($row->base),
                    'tax' => $this->money($row->amount),
                ]])->all(),
                'unremitted' => ['rows' => (int) $unremitted->rows_count, 'tax' => $this->money($unremitted->amount)],
            ];
        };

        return new FinanceListing(
            $query,
            fn (object $row) => [
                'id' => (int) $row->id,
                'charged_at' => $this->moment($row->charged_at),
                'kind' => $row->kind,
                'wager_id' => (int) $row->wager_id,
                'wager_reference' => $row->wager_reference,
                'settlement_version' => (int) $row->settlement_version,
                'product' => $row->product_code,
                'customer_id' => (int) $row->customer_id,
                'customer_name' => $row->customer_name,
                'tax_base' => $this->money($row->tax_base),
                'rate' => (float) $row->rate,
                'amount' => $this->money($row->amount),
                'remittance_id' => $row->remittance_id === null ? null : (int) $row->remittance_id,
                'kra_reference' => $row->kra_reference,
            ],
            $summary,
            ['id', 'charged_at', 'kind', 'wager_id', 'wager_reference', 'settlement_version', 'product', 'customer_id', 'customer_name', 'tax_base', 'rate', 'amount', 'remittance_id', 'kra_reference'],
        );
    }

    /**
     * One row per calendar month the range touches: what was charged and credited, what has been remitted,
     * what is still owed (negative for a credit carried forward) and when the return is due. Always covers
     * every customer.
     *
     * @return list<array<string, mixed>>
     */
    public function returns(FinanceDateRange $range): array
    {
        $timezone = config('app.timezone');
        $from = $range->from->startOfMonth();
        $to = $range->to->endOfMonth();

        $rows = DB::table('winnings_tax_charges')
            ->whereBetween('charged_at', [$from, $to])
            ->selectRaw('DATE(charged_at) as day, kind, (remittance_id IS NOT NULL) as remitted, COUNT(*) as rows_count, SUM(amount) as amount')
            ->groupByRaw('DATE(charged_at), kind, (remittance_id IS NOT NULL)')
            ->get();

        $months = [];
        for ($month = $from; $month->lessThanOrEqualTo($to); $month = $month->addMonthNoOverflow()) {
            $months[$month->format('Y-m')] = [
                'period' => $month->format('Y-m'),
                'period_start' => $month->toDateString(),
                'period_end' => $month->endOfMonth()->toDateString(),
                'due_date' => $this->dueDate($month)->toDateString(),
                'charges' => 0,
                'tax_charged' => 0.0,
                'tax_reversed' => 0.0,
                'tax_due' => 0.0,
                'tax_remitted' => 0.0,
                'outstanding' => 0.0,
            ];
        }

        foreach ($rows as $row) {
            $key = CarbonImmutable::parse($row->day, $timezone)->format('Y-m');

            if ($row->kind === WinningsTaxCharge::KIND_REVERSAL) {
                $months[$key]['tax_reversed'] += -(float) $row->amount;
            } else {
                $months[$key]['charges'] += (int) $row->rows_count;
                $months[$key]['tax_charged'] += (float) $row->amount;
            }

            if ((bool) $row->remitted) {
                $months[$key]['tax_remitted'] += (float) $row->amount;
            }
        }

        $today = CarbonImmutable::today($timezone);
        $tolerance = (float) config('finance.reconciliation.tolerance');
        $toRemit = $this->amountsToRemit();

        return array_values(array_map(function (array $month) use ($today, $tolerance, $toRemit) {
            $month['tax_due'] = $month['tax_charged'] - $month['tax_reversed'];
            $month['outstanding'] = $month['tax_due'] - $month['tax_remitted'];
            $month['amount_to_remit'] = $toRemit[$month['period']] ?? 0.0;
            $month = $this->rounded($month);
            $month['overdue'] = $month['amount_to_remit'] > $tolerance && $today->greaterThan(CarbonImmutable::parse($month['due_date'], config('app.timezone')));

            return $month;
        }, $months));
    }

    /**
     * Months with winnings tax still to remit after their due date, from all time. The amount is what a
     * remittance for the month would record, so a month whose charges earlier credits cover is never overdue.
     *
     * @return list<array{period: string, due_date: string, outstanding: float}>
     */
    public function overdueReturns(): array
    {
        $timezone = config('app.timezone');
        $today = CarbonImmutable::today($timezone);
        $tolerance = (float) config('finance.reconciliation.tolerance');
        $overdue = [];

        foreach ($this->amountsToRemit() as $period => $amount) {
            $dueDate = $this->dueDate(CarbonImmutable::parse($period.'-01', $timezone));

            if ($amount > $tolerance && $today->greaterThan($dueDate)) {
                $overdue[] = ['period' => $period, 'due_date' => $dueDate->toDateString(), 'outstanding' => $amount];
            }
        }

        return $overdue;
    }

    /**
     * Per month with unremitted rows, from all time, what a remittance for that whole month would record:
     * the month's unremitted charges and credits plus every unremitted credit from before it (as
     * recordRemittance attaches them), or 0 when that net is not positive and the remittance would be refused.
     *
     * @return array<string, float>
     */
    private function amountsToRemit(): array
    {
        $rows = DB::table('winnings_tax_charges')
            ->whereNull('remittance_id')
            ->selectRaw("DATE_FORMAT(charged_at, '%Y-%m') as period, kind, SUM(amount) as amount")
            ->groupByRaw("DATE_FORMAT(charged_at, '%Y-%m'), kind")
            ->orderBy('period')
            ->get();

        $months = [];
        foreach ($rows as $row) {
            $months[$row->period] ??= ['net' => 0.0, 'credits' => 0.0];
            $months[$row->period]['net'] += (float) $row->amount;

            if ($row->kind === WinningsTaxCharge::KIND_REVERSAL) {
                $months[$row->period]['credits'] += (float) $row->amount;
            }
        }

        ksort($months);
        $earlierCredits = 0.0;
        $amounts = [];

        foreach ($months as $period => $month) {
            $amounts[$period] = $this->money(max(0.0, $month['net'] + $earlierCredits));
            $earlierCredits += $month['credits'];
        }

        return $amounts;
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
        $base = fn (string $state) => DB::table('winnings_tax_remittances as r')
            ->where('r.period_start', '<=', $range->to->toDateString())
            ->where('r.period_end', '>=', $range->from->toDateString())
            ->when($state === 'active', fn (Builder $query) => $query->whereNull('r.voided_at'))
            ->when($state === 'voided', fn (Builder $query) => $query->whereNotNull('r.voided_at'));

        $query = $base($status)
            ->selectRaw('r.*, (SELECT COUNT(*) FROM winnings_tax_charges x WHERE x.remittance_id = r.id) as charges')
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
     * Record a payment to KRA. Every unremitted row in the period is attached to it, plus every unremitted
     * credit from before the period, so a reversal of tax already paid lowers this return. amount_due is
     * their net. Returns null when nothing is owed: no rows, or credits that cover the charges (they then
     * stay unremitted and carry into a later return).
     *
     * @param  array{period_start: string, period_end: string, amount_paid: numeric-string|float|int, kra_reference: string, paid_at?: ?string}  $data
     */
    public function recordRemittance(array $data, ?string $actor): ?WinningsTaxRemittance
    {
        $timezone = config('app.timezone');
        $start = CarbonImmutable::parse($data['period_start'], $timezone)->startOfDay();
        $end = CarbonImmutable::parse($data['period_end'], $timezone)->endOfDay();

        return DB::transaction(function () use ($data, $actor, $start, $end, $timezone) {
            $rows = WinningsTaxCharge::whereNull('remittance_id')
                ->where(fn ($query) => $query->whereBetween('charged_at', [$start, $end])
                    ->orWhere(fn ($credits) => $credits->where('kind', WinningsTaxCharge::KIND_REVERSAL)->where('charged_at', '<', $start)))
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'amount']);

            $due = $this->money($rows->sum('amount'));

            if ($rows->isEmpty() || $due <= 0) {
                return null;
            }

            $remittance = WinningsTaxRemittance::create([
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'amount_due' => $due,
                'amount_paid' => $data['amount_paid'],
                'kra_reference' => $data['kra_reference'],
                'paid_at' => isset($data['paid_at']) ? CarbonImmutable::parse($data['paid_at'], $timezone) : now(),
                'recorded_by' => $actor,
            ]);

            WinningsTaxCharge::whereKey($rows->modelKeys())->update(['remittance_id' => $remittance->id]);

            return $remittance->loadCount('charges');
        });
    }

    /**
     * Void a remittance entered in error. Its rows become unremitted again. Returns null when it was already
     * voided, so a repeat call changes nothing.
     */
    public function voidRemittance(int $id, string $reason, ?string $actor): ?WinningsTaxRemittance
    {
        return DB::transaction(function () use ($id, $reason, $actor) {
            $remittance = WinningsTaxRemittance::lockForUpdate()->findOrFail($id);

            if ($remittance->isVoided()) {
                return null;
            }

            WinningsTaxCharge::where('remittance_id', $remittance->id)->update(['remittance_id' => null]);

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
    public function presentRemittance(WinningsTaxRemittance $remittance): array
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
     * Charged and credited tax per day and kind in the range, for the summary.
     *
     * @return iterable<object{day: string, kind: string, rows: int, amount: string}>
     */
    public function chargeRows(FinanceDateRange $range): iterable
    {
        return $this->chargeBase($range, [])
            ->selectRaw('DATE(x.charged_at) as day, x.kind, COUNT(*) as `rows`, SUM(x.amount) as amount')
            ->groupByRaw('DATE(x.charged_at), x.kind')
            ->get();
    }

    /**
     * Amounts paid to KRA per payment day in the range, leaving out voided remittances.
     *
     * @return iterable<object{day: string, amount: string}>
     */
    public function remittanceRows(FinanceDateRange $range): iterable
    {
        return DB::table('winnings_tax_remittances')
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
        $kind = $filters['kind'] ?? null;
        $status = $filters['status'] ?? null;
        $productIds = $this->productIdsFor($filters);

        return DB::table('winnings_tax_charges as x')
            ->whereBetween('x.charged_at', [$range->from, $range->to])
            ->when($excluded !== [], fn (Builder $query) => $query->whereNotIn('x.customer_id', $excluded))
            ->when(in_array($kind, [WinningsTaxCharge::KIND_CHARGE, WinningsTaxCharge::KIND_REVERSAL], true), fn (Builder $query) => $query->where('x.kind', $kind))
            ->when($status === 'remitted', fn (Builder $query) => $query->whereNotNull('x.remittance_id'))
            ->when($status === 'unremitted', fn (Builder $query) => $query->whereNull('x.remittance_id'))
            ->when(isset($filters['customer_id']), fn (Builder $query) => $query->where('x.customer_id', (int) $filters['customer_id']))
            ->when($productIds !== null, fn (Builder $query) => $query->whereIn('x.product_id', $productIds ?: [0]));
    }

    /**
     * The return and payment for a month are due on the filing day of the next month.
     */
    private function dueDate(CarbonImmutable $month): CarbonImmutable
    {
        $nextMonth = $month->startOfMonth()->addMonthNoOverflow();

        return $nextMonth->setDay(min((int) config('finance.winnings_tax.filing_day', 20), $nextMonth->daysInMonth));
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
