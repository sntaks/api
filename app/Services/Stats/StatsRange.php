<?php

namespace App\Services\Stats;

use App\Services\FinanceDateRange;
use Carbon\CarbonImmutable;

/**
 * The window a stats answer covers and the window it is compared with.
 */
final readonly class StatsRange
{
    public const COMPARE_PREVIOUS_PERIOD = 'previous_period';

    public const COMPARE_SAME_PERIOD_LAST_YEAR = 'same_period_last_year';

    public const COMPARES = [self::COMPARE_PREVIOUS_PERIOD, self::COMPARE_SAME_PERIOD_LAST_YEAR];

    public const PERIODS = ['today', 'yesterday', '7d', '30d', 'this_month', 'last_month', 'this_year'];

    public function __construct(
        public FinanceDateRange $current,
        public FinanceDateRange $compare,
        public string $compareMode,
    ) {}

    public static function compared(FinanceDateRange $current, string $mode = self::COMPARE_PREVIOUS_PERIOD): self
    {
        [$from, $to] = $mode === self::COMPARE_SAME_PERIOD_LAST_YEAR
            ? [$current->from->subYearNoOverflow(), $current->to->subYearNoOverflow()]
            : [$current->from->subDays($current->days()), $current->from->subDay()->endOfDay()];

        return new self($current, new FinanceDateRange($from->startOfDay(), $to->endOfDay(), $current->groupBy, $current->excludeTest), $mode);
    }

    /**
     * from and to (Y-m-d, Nairobi) for a named period.
     *
     * @return array{from: string, to: string}
     */
    public static function period(string $period): array
    {
        $today = CarbonImmutable::today(config('app.timezone'));

        [$from, $to] = match ($period) {
            'yesterday' => [$today->subDay(), $today->subDay()],
            '7d' => [$today->subDays(6), $today],
            '30d' => [$today->subDays(29), $today],
            'this_month' => [$today->startOfMonth(), $today],
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()],
            'this_year' => [$today->startOfYear(), $today],
            default => [$today, $today],
        };

        return ['from' => $from->toDateString(), 'to' => $to->toDateString()];
    }
}
