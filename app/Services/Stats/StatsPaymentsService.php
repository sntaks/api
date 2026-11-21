<?php

namespace App\Services\Stats;

use App\Models\WithdrawalApproval;
use App\Services\FinanceDateRange;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * /stats/payments: deposits, STK pushes, withdrawals, held withdrawals and bet shop cash, by day and hour.
 * Platform-wide: it takes no product or game filter.
 */
class StatsPaymentsService
{
    use ReadsStatsRollups;

    public function __construct(private StatsRollupService $rollups) {}

    /**
     * @return array<string, mixed>
     */
    public function payments(FinanceDateRange $range): array
    {
        $warnings = $this->freshen([$range]);
        $cells = DB::table('stats_daily_payments')
            ->whereBetween('date', [$range->from->toDateString(), $range->to->toDateString()])
            ->where('excludes_test', $range->excludeTest)
            ->get();

        $sum = fn (Collection $rows) => [
            'count' => (int) $rows->sum('events'),
            'amount' => (float) $rows->sum('amount'),
        ];
        $of = fn (string $kind, ?string $outcome = null) => $cells->where('kind', $kind)
            ->when($outcome !== null, fn (Collection $rows) => str_ends_with($outcome, '*')
                ? $rows->filter(fn ($row) => str_starts_with($row->outcome, rtrim($outcome, '*')))
                : $rows->where('outcome', $outcome));
        $seconds = fn (Collection $rows) => $rows->sum('seconds_count') > 0 ? $rows->sum('seconds_total') / $rows->sum('seconds_count') : null;
        $average = fn (array $figures) => $figures['count'] > 0 ? $figures['amount'] / $figures['count'] : null;
        $rate = fn (int $part, int $whole) => $whole > 0 ? $part / $whole * 100 : null;

        $credited = $sum($of(StatsPaymentRollup::KIND_DEPOSIT, 'credited'));
        $stk = $of(StatsPaymentRollup::KIND_STK_PUSH);
        $stkDone = $sum($of(StatsPaymentRollup::KIND_STK_PUSH, 'completed'));
        $paid = $sum($of(StatsPaymentRollup::KIND_WITHDRAWAL, 'paid'));
        $failed = $of(StatsPaymentRollup::KIND_WITHDRAWAL, 'failed_*');
        $settledCount = $paid['count'] + (int) $failed->sum('events');
        $decisions = $of(StatsPaymentRollup::KIND_HELD_DECISION);
        $pending = DB::table('outgoing_payments')->whereNotIn('disburse', [2, 3])->selectRaw('COUNT(*) as count, COALESCE(SUM(amount), 0) as amount')->first();
        $awaiting = DB::table('withdrawal_approvals')->where('status', WithdrawalApproval::STATUS_AWAITING)
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(amount), 0) as amount, MIN(created_at) as oldest')->first();

        return $this->rounded([
            'range' => $this->rangeOf($range),
            'deposits' => $credited + [
                'average' => $average($credited),
                'unmatched' => $sum($of(StatsPaymentRollup::KIND_DEPOSIT, 'unmatched')),
                'by_day' => $this->byDay($range, $of(StatsPaymentRollup::KIND_DEPOSIT, 'credited')),
                'by_hour' => $this->byHour($of(StatsPaymentRollup::KIND_DEPOSIT, 'credited')),
            ],
            'stk_push' => $sum($stk) + [
                'completed' => $stkDone,
                'not_completed' => $sum($of(StatsPaymentRollup::KIND_STK_PUSH, 'not_completed')),
                'success_rate' => $rate($stkDone['count'], (int) $stk->sum('events')),
                'avg_seconds_to_complete' => $seconds($of(StatsPaymentRollup::KIND_STK_PUSH, 'completed')),
            ],
            'withdrawals' => [
                'requested' => $sum($of(StatsPaymentRollup::KIND_WITHDRAWAL_REQUEST)),
                'paid' => $paid + ['average' => $average($paid)],
                'failed' => $sum($failed) + ['by_reason' => $failed->groupBy(fn ($row) => substr($row->outcome, 7))->map($sum)->all()],
                'failure_rate' => $rate((int) $failed->sum('events'), $settledCount),
                'avg_seconds_to_complete' => $seconds($of(StatsPaymentRollup::KIND_WITHDRAWAL)),
                'pending_now' => ['count' => (int) $pending->count, 'amount' => (float) $pending->amount],
                'by_day' => $this->byDay($range, $of(StatsPaymentRollup::KIND_WITHDRAWAL, 'paid')),
                'by_hour' => $this->byHour($of(StatsPaymentRollup::KIND_WITHDRAWAL, 'paid')),
            ],
            'held_withdrawals' => [
                'threshold' => (float) config('responsible_gambling.approval_threshold'),
                'requested' => $sum($of(StatsPaymentRollup::KIND_HELD_REQUEST)),
                'decided' => collect([WithdrawalApproval::STATUS_APPROVED, WithdrawalApproval::STATUS_REJECTED, WithdrawalApproval::STATUS_CANCELLED])
                    ->mapWithKeys(fn (string $status) => [$status => $sum($decisions->where('outcome', $status))])->all(),
                'avg_wait_seconds' => $seconds($decisions),
                'awaiting_now' => [
                    'count' => (int) $awaiting->count,
                    'amount' => (float) $awaiting->amount,
                    'oldest_since' => $this->moment($awaiting->oldest),
                ],
            ],
            'bet_shops' => [
                'loads' => $sum($of(StatsPaymentRollup::KIND_SHOP_LOAD, 'loaded')),
                'loads_reversed' => $sum($of(StatsPaymentRollup::KIND_SHOP_LOAD, 'reversed')),
                'cash_outs' => $sum($of(StatsPaymentRollup::KIND_SHOP_CASHOUT, 'paid')) + [
                    'avg_seconds_to_pay' => $seconds($of(StatsPaymentRollup::KIND_SHOP_CASHOUT, 'paid')),
                ],
            ],
            'generated_at' => $this->generatedAt($range),
            'warnings' => $warnings,
            'notes' => StatsVocabulary::paymentNotes(),
        ]);
    }

    /**
     * @return list<array{date: string, count: int, amount: float}>
     */
    private function byDay(FinanceDateRange $range, Collection $rows): array
    {
        $buckets = [];
        foreach ($rows as $row) {
            $bucket = $range->bucketFor(CarbonImmutable::parse($row->date, config('app.timezone')));
            $buckets[$bucket]['count'] = ($buckets[$bucket]['count'] ?? 0) + (int) $row->events;
            $buckets[$bucket]['amount'] = ($buckets[$bucket]['amount'] ?? 0.0) + (float) $row->amount;
        }

        return array_map(fn (string $bucket) => ['date' => $bucket, 'count' => $buckets[$bucket]['count'] ?? 0, 'amount' => $buckets[$bucket]['amount'] ?? 0.0], $range->buckets());
    }

    /**
     * @return list<array{hour: int, count: int, amount: float}>
     */
    private function byHour(Collection $rows): array
    {
        return array_map(fn (int $hour) => [
            'hour' => $hour,
            'count' => (int) $rows->where('hour', $hour)->sum('events'),
            'amount' => (float) $rows->where('hour', $hour)->sum('amount'),
        ], range(0, 23));
    }

    private function moment(?string $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value, config('app.timezone'))->toIso8601String();
    }
}
