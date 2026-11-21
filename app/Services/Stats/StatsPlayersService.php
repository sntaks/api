<?php

namespace App\Services\Stats;

use App\Models\Customer;
use App\Services\Concerns\BuildsFinanceQueries;
use App\Services\FinanceDateRange;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * /stats/players and /stats/players/top, read from the daily rollups.
 */
class StatsPlayersService
{
    use BuildsFinanceQueries;
    use ReadsStatsRollups;

    public const SORTS = ['turnover', 'ggr', 'deposits', 'net_loss', 'wins'];

    /** Upper bounds of the stake bands in KES; the last band has none. */
    private const STAKE_BANDS = ['under_100' => 100, '100_1k' => 1000, '1k_10k' => 10000, '10k_100k' => 100000, '100k_plus' => null];

    private const CONVERSION_DAYS = [1, 7, 30];

    private const RETENTION_WEEKS = 8;

    public function __construct(private StatsRollupService $rollups) {}

    /**
     * @param  array<string, string>  $filters  product, game
     * @return array<string, mixed>
     */
    public function players(FinanceDateRange $range, array $filters): array
    {
        $productIds = $this->productIdsFor($filters);
        $today = CarbonImmutable::today(config('app.timezone'));
        $end = $range->to->startOfDay();
        $window = fn (CarbonImmutable $from, CarbonImmutable $to) => new FinanceDateRange($from->startOfDay(), $to->endOfDay(), FinanceDateRange::GROUP_BY_DAY, $range->excludeTest);
        $churnWindow = $window($end->subDays(59), $range->to);
        $cohortWindow = $window($range->from, $end->addDays(7 * (self::RETENTION_WEEKS + 1))->min($today));
        $warnings = $this->freshen([$range, $churnWindow, $cohortWindow]);

        return $this->rounded([
            'range' => $this->rangeOf($range),
            'registrations' => $this->registrations($range),
            'first_deposit_conversion' => $this->conversion($range),
            'active' => $this->active($range, $productIds),
            'retention_cohorts' => $this->cohorts($range, $productIds),
            'churn' => $this->churn($range, $productIds),
            'stake_bands' => $this->stakeBands($range, $productIds),
            'channel' => $this->channels($range, $productIds),
            'generated_at' => $this->generatedAt($range),
            'warnings' => $warnings,
            'notes' => [...StatsVocabulary::notes(), ...StatsVocabulary::playersNotes()],
        ]);
    }

    /**
     * @param  array<string, string>  $filters  product, game
     * @return array<string, mixed>
     */
    public function top(FinanceDateRange $range, array $filters, string $sort, int $limit): array
    {
        $productIds = $this->productIdsFor($filters);
        $warnings = $this->freshen([$range]);
        $in = [$range->from->toDateString(), $range->to->toDateString()];
        $testFilter = fn (Builder $query, string $alias) => $query->when($range->excludeTest, fn (Builder $inner) => $inner->where("{$alias}.is_test", false));

        $money = fn () => DB::table('stats_daily_player_money as m')
            ->whereBetween('m.date', $in)
            ->tap(fn (Builder $query) => $testFilter($query, 'm'))
            ->when($productIds !== null, fn (Builder $query) => $query->whereIn('m.product_id', $productIds ?: [0]));
        $cash = fn () => DB::table('stats_daily_depositors as d')
            ->whereBetween('d.date', $in)
            ->tap(fn (Builder $query) => $testFilter($query, 'd'));

        $leaders = $sort === 'deposits'
            ? $cash()->selectRaw('d.customer_id, SUM(d.amount) as metric')
                ->where('d.deposits', '>', 0)
                ->when($productIds !== null, fn (Builder $query) => $query->whereIn('d.customer_id', $this->playerRows($range, $productIds)->select('a.customer_id')))
                ->groupBy('d.customer_id')
            : $money()->selectRaw('m.customer_id, '.match ($sort) {
                'ggr' => 'SUM(m.ggr)',
                'net_loss' => 'SUM(m.turnover - m.payouts)',
                'wins' => 'SUM(m.wins)',
                default => 'SUM(m.turnover)',
            }.' as metric')->groupBy('m.customer_id');

        $ids = $leaders->orderByDesc('metric')->orderBy('customer_id')->limit($limit)->pluck('customer_id')->map(fn ($id) => (int) $id)->all();

        $play = $money()->whereIn('m.customer_id', $ids ?: [0])
            ->selectRaw('m.customer_id, SUM(m.turnover) as turnover, SUM(m.payouts) as payouts, SUM(m.ggr) as ggr, SUM(m.wins) as wins')
            ->groupBy('m.customer_id')->get()->keyBy('customer_id');
        $wallet = $cash()->whereIn('d.customer_id', $ids ?: [0])
            ->selectRaw('d.customer_id, SUM(d.amount) as deposits, SUM(d.withdrawal_amount) as withdrawals')
            ->groupBy('d.customer_id')->get()->keyBy('customer_id');
        $games = $this->playerRows($range, $productIds)->whereIn('a.customer_id', $ids ?: [0])
            ->selectRaw('a.customer_id, SUM(a.entries) as games_played')
            ->groupBy('a.customer_id')->pluck('games_played', 'customer_id');
        $lastActive = DB::table('stats_daily_player_product')->whereIn('customer_id', $ids ?: [0])->where('date', '<=', $in[1])
            ->selectRaw('customer_id, MAX(date) as last_active')->groupBy('customer_id')->pluck('last_active', 'customer_id');
        $customers = Customer::withTrashed()->whereKey($ids ?: [0])->get(['id', 'name', 'player_no'])->keyBy('id');

        $rows = array_map(function (int $id) use ($play, $wallet, $games, $lastActive, $customers) {
            $figures = $play[$id] ?? null;
            $turnover = (float) ($figures->turnover ?? 0);
            $payouts = (float) ($figures->payouts ?? 0);

            return [
                'customer_id' => $id,
                'player_no' => $customers[$id]->player_no ?? null,
                'display_name' => isset($customers[$id]) ? $customers[$id]->displayName() : null,
                'turnover' => $turnover,
                'payouts' => $payouts,
                'net_loss' => $turnover - $payouts,
                'ggr' => (float) ($figures->ggr ?? 0),
                'wins' => (int) ($figures->wins ?? 0),
                'deposits' => (float) ($wallet[$id]->deposits ?? 0),
                'withdrawals' => (float) ($wallet[$id]->withdrawals ?? 0),
                'games_played' => (int) ($games[$id] ?? 0),
                'last_active' => isset($lastActive[$id]) ? CarbonImmutable::parse($lastActive[$id])->toDateString() : null,
            ];
        }, $ids);

        return $this->rounded([
            'range' => $this->rangeOf($range),
            'sort' => $sort,
            'players' => $rows,
            'generated_at' => $this->generatedAt($range),
            'warnings' => $warnings,
            'notes' => StatsVocabulary::topPlayersNotes(),
        ]);
    }

    /**
     * @return array{total: int, series: list<array{date: string, registrations: int}>}
     */
    private function registrations(FinanceDateRange $range): array
    {
        $byBucket = [];
        foreach (DB::table('stats_daily_platform')->whereBetween('date', [$range->from->toDateString(), $range->to->toDateString()])->where('excludes_test', $range->excludeTest)->get(['date', 'new_players']) as $row) {
            $bucket = $range->bucketFor(CarbonImmutable::parse($row->date, config('app.timezone')));
            $byBucket[$bucket] = ($byBucket[$bucket] ?? 0) + (int) $row->new_players;
        }

        return [
            'total' => array_sum($byBucket),
            'series' => array_map(fn (string $bucket) => ['date' => $bucket, 'registrations' => $byBucket[$bucket] ?? 0], $range->buckets()),
        ];
    }

    /**
     * Customers registered in the range who made a first deposit within 1, 7 and 30 days (the registration day
     * counts as day 1). Only customers registered long enough ago to have had the full window are eligible.
     *
     * @return array<string, array{eligible: int, converted: int, rate: float|null}>
     */
    private function conversion(FinanceDateRange $range): array
    {
        $today = CarbonImmutable::today(config('app.timezone'))->toDateString();
        $excluded = $this->excludedCustomerIds($range);
        $select = [];
        foreach (self::CONVERSION_DAYS as $days) {
            $eligible = 'DATE_ADD(DATE(c.created_at), INTERVAL '.($days - 1)." DAY) <= '{$today}'";
            $select[] = "SUM({$eligible}) as eligible_{$days}";
            $select[] = "SUM({$eligible} AND d.date IS NOT NULL AND DATEDIFF(d.date, DATE(c.created_at)) < {$days}) as converted_{$days}";
        }

        $row = DB::table('customers as c')
            ->leftJoin('stats_daily_depositors as d', fn ($join) => $join->on('d.customer_id', '=', 'c.id')->where('d.is_first', true))
            ->whereBetween('c.created_at', [$range->from, $range->to])
            ->where('c.id', '!=', (int) config('wallets.house_wallet_id', 1))
            ->when($excluded !== [], fn (Builder $query) => $query->whereNotIn('c.id', $excluded))
            ->selectRaw('COUNT(*) as registered, '.implode(', ', $select))
            ->first();

        $result = ['registered' => (int) $row->registered];
        foreach (self::CONVERSION_DAYS as $days) {
            $eligible = (int) $row->{"eligible_{$days}"};
            $converted = (int) $row->{"converted_{$days}"};
            $result["within_{$days}_days"] = ['eligible' => $eligible, 'converted' => $converted, 'rate' => $eligible > 0 ? $converted / $eligible * 100 : null];
        }

        return $result;
    }

    /**
     * Distinct players per day (DAU), per calendar week from Monday (WAU) and per calendar month (MAU).
     *
     * @param  list<int>|null  $productIds
     * @return array{daily: list<array{date: string, players: int}>, weekly: list<array{date: string, players: int}>, monthly: list<array{date: string, players: int}>}
     */
    private function active(FinanceDateRange $range, ?array $productIds): array
    {
        $series = function (string $groupBy) use ($range, $productIds) {
            $bucketed = new FinanceDateRange($range->from, $range->to, $groupBy, $range->excludeTest);
            $counts = $this->playersByBucket($bucketed, $productIds, null)[0] ?? [];

            return array_map(fn (string $bucket) => ['date' => $bucket, 'players' => $counts[$bucket] ?? 0], $bucketed->buckets());
        };

        return [
            'daily' => $series(FinanceDateRange::GROUP_BY_DAY),
            'weekly' => $series(FinanceDateRange::GROUP_BY_WEEK),
            'monthly' => $series(FinanceDateRange::GROUP_BY_MONTH),
        ];
    }

    /**
     * Weekly cohorts by registration week (Monday): the share of each cohort active in weeks 1 to 8 after it,
     * in all games or the game or product filtered. A week that has not ended yet is null.
     *
     * @param  list<int>|null  $productIds
     * @return list<array{week: string, registered: int, weeks: array<string, float|null>}>
     */
    private function cohorts(FinanceDateRange $range, ?array $productIds): array
    {
        $excluded = $this->excludedCustomerIds($range);
        $cohort = 'DATE_SUB(DATE(c.created_at), INTERVAL WEEKDAY(c.created_at) DAY)';
        $customers = fn () => DB::table('customers as c')
            ->whereBetween('c.created_at', [$range->from, $range->to])
            ->where('c.id', '!=', (int) config('wallets.house_wallet_id', 1))
            ->when($excluded !== [], fn (Builder $query) => $query->whereNotIn('c.id', $excluded));

        $sizes = $customers()->selectRaw("{$cohort} as week, COUNT(*) as registered")->groupByRaw($cohort)->orderByRaw($cohort)->get();
        $weekOf = "FLOOR(DATEDIFF(a.date, {$cohort}) / 7)";
        $active = $customers()
            ->join('stats_daily_player_product as a', 'a.customer_id', '=', 'c.id')
            ->whereRaw("a.date >= DATE_ADD({$cohort}, INTERVAL 7 DAY)")
            ->whereRaw("a.date < DATE_ADD({$cohort}, INTERVAL ".(7 * (self::RETENTION_WEEKS + 1)).' DAY)')
            ->when($range->excludeTest, fn (Builder $query) => $query->where('a.is_test', false))
            ->when($productIds !== null, fn (Builder $query) => $query->whereIn('a.product_id', $productIds ?: [0]))
            ->selectRaw("{$cohort} as week, {$weekOf} as offset, COUNT(DISTINCT a.customer_id) as players")
            ->groupByRaw("{$cohort}, {$weekOf}")
            ->get();

        $byCohort = [];
        foreach ($active as $row) {
            $byCohort[CarbonImmutable::parse($row->week)->toDateString()][(int) $row->offset] = (int) $row->players;
        }

        $today = CarbonImmutable::today(config('app.timezone'));

        return $sizes->map(function ($size) use ($byCohort, $today) {
            $week = CarbonImmutable::parse($size->week, config('app.timezone'));
            $weeks = [];
            for ($offset = 1; $offset <= self::RETENTION_WEEKS; $offset++) {
                $weeks["week_{$offset}"] = $week->addDays(7 * $offset + 6)->greaterThanOrEqualTo($today)
                    ? null
                    : ($byCohort[$week->toDateString()][$offset] ?? 0) / (int) $size->registered * 100;
            }

            return ['week' => $week->toDateString(), 'registered' => (int) $size->registered, 'weeks' => $weeks];
        })->all();
    }

    /**
     * Players active in the 30 days before the last 30 days ending on `to`, and how many of them did not play
     * in those last 30 days.
     *
     * @param  list<int>|null  $productIds
     * @return array{previous_active: int, churned: int, rate: float|null, previous: array{from: string, to: string}, current: array{from: string, to: string}}
     */
    private function churn(FinanceDateRange $range, ?array $productIds): array
    {
        $end = $range->to->startOfDay();
        $current = new FinanceDateRange($end->subDays(29), $range->to, FinanceDateRange::GROUP_BY_DAY, $range->excludeTest);
        $previous = new FinanceDateRange($end->subDays(59), $end->subDays(30)->endOfDay(), FinanceDateRange::GROUP_BY_DAY, $range->excludeTest);

        $before = $this->activePlayers($previous, $productIds);
        $churned = $this->playerRows($previous, $productIds)
            ->whereNotIn('a.customer_id', $this->playerRows($current, $productIds)->select('a.customer_id'))
            ->distinct()
            ->count('a.customer_id');

        return [
            'previous_active' => $before,
            'churned' => $churned,
            'rate' => $before > 0 ? $churned / $before * 100 : null,
            'previous' => ['from' => $previous->from->toDateString(), 'to' => $previous->to->toDateString()],
            'current' => ['from' => $current->from->toDateString(), 'to' => $current->to->toDateString()],
        ];
    }

    /**
     * Active players by the total they staked in the range.
     *
     * @param  list<int>|null  $productIds
     * @return array<string, array{players: int, stakes: float}>
     */
    private function stakeBands(FinanceDateRange $range, ?array $productIds): array
    {
        $band = 'CASE';
        foreach (self::STAKE_BANDS as $name => $limit) {
            $band .= $limit === null ? " ELSE '{$name}' END" : " WHEN x.stakes < {$limit} THEN '{$name}'";
        }

        $rows = DB::query()
            ->fromSub($this->playerRows($range, $productIds)->selectRaw('a.customer_id, SUM(a.stakes) as stakes')->groupBy('a.customer_id'), 'x')
            ->selectRaw("{$band} as band, COUNT(*) as players, SUM(x.stakes) as stakes")
            ->groupByRaw($band)
            ->get()
            ->keyBy('band');

        return collect(self::STAKE_BANDS)->map(fn ($limit, string $name) => [
            'players' => (int) ($rows[$name]->players ?? 0),
            'stakes' => (float) ($rows[$name]->stakes ?? 0),
        ])->all();
    }

    /**
     * @param  list<int>|null  $productIds
     * @return list<array{channel: string, entries: int, stakes: float, player_days: int}>
     */
    private function channels(FinanceDateRange $range, ?array $productIds): array
    {
        return DB::table('stats_daily_channels')
            ->whereBetween('date', [$range->from->toDateString(), $range->to->toDateString()])
            ->where('excludes_test', $range->excludeTest)
            ->when($productIds !== null, fn (Builder $query) => $query->whereIn('product_id', $productIds ?: [0]))
            ->selectRaw('channel, SUM(entries) as entries, SUM(stakes) as stakes, SUM(players) as player_days')
            ->groupBy('channel')
            ->orderByDesc('entries')
            ->get()
            ->map(fn ($row) => ['channel' => $row->channel, 'entries' => (int) $row->entries, 'stakes' => (float) $row->stakes, 'player_days' => (int) $row->player_days])
            ->all();
    }
}
