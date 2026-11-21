<?php

namespace App\Services\Stats;

use App\Models\Game;
use App\Models\Product;
use App\Services\Concerns\BuildsFinanceQueries;
use App\Services\FinanceDateRange;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * /stats/dashboard and /stats/games, read from the daily rollups (see StatsRollupService).
 */
class StatsDashboardService
{
    use BuildsFinanceQueries;
    use ReadsStatsRollups;

    private const MONEY_FIGURES = ['bets', 'voids', 'turnover', 'payouts', 'ggr', 'promotions', 'free_bet_value', 'winnings_tax', 'house_banked_turnover'];

    private const WEEKDAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    public function __construct(
        private StatsRollupService $rollups,
        private StatsLiabilityService $liability,
    ) {}

    /**
     * @param  array<string, string>  $filters  product, game
     * @return array<string, mixed>
     */
    public function dashboard(StatsRange $range, array $filters): array
    {
        $productIds = $this->productIdsFor($filters);
        $current = $range->current;
        $monthWindow = new FinanceDateRange($current->to->startOfDay()->subDays(29), $current->to, FinanceDateRange::GROUP_BY_DAY, $current->excludeTest);
        $warnings = $this->freshen([$current, $range->compare, $monthWindow]);

        $now = $this->block($current, $productIds);
        $before = $this->block($range->compare, $productIds);
        $liability = $this->liability->liability($filters, $current->excludeTest);
        $openByProduct = collect($liability['by_product'])->keyBy('product');

        $byProduct = [];
        foreach ($now['by_product'] as $code => $row) {
            $open = $openByProduct[$code] ?? null;
            $byProduct[] = $this->compared($row + [
                'open_wagers' => $open['open_wagers'] ?? 0,
                'open_stakes' => $open['open_stakes'] ?? 0.0,
                'open_potential_payout' => $open['open_potential_payout'] ?? 0.0,
            ], $before['by_product'][$code] ?? null, ['product', 'game', 'revenue_model', 'open_wagers', 'open_stakes', 'open_potential_payout']);
        }

        $byGame = [];
        foreach ($now['by_game'] as $code => $row) {
            $byGame[] = $this->compared($row, $before['by_game'][$code] ?? null, ['game', 'products', 'series']);
        }

        return $this->rounded([
            'range' => $this->rangeOf($current),
            'compare' => ['mode' => $range->compareMode] + $this->rangeOf($range->compare),
            'totals' => $this->compared($now['totals'], $before['totals']),
            'series' => $now['series'],
            'previous_series' => $before['series'],
            'by_game' => $byGame,
            'by_product' => $byProduct,
            'players' => $this->compared($this->playerBlock($current, $productIds), $this->playerBlock($range->compare, $productIds)),
            'liability' => [
                'open_wagers' => $liability['open_wagers'],
                'open_stakes' => $liability['open_stakes'],
                'open_potential_payout' => $liability['open_potential_payout'],
                'by_product' => array_map(fn (array $row) => [
                    'product' => $row['product'],
                    'open_stakes' => $row['open_stakes'],
                    'open_potential_payout' => $row['open_potential_payout'],
                ], $liability['by_product']),
            ],
            'generated_at' => $this->generatedAt($current),
            'warnings' => $warnings,
            'notes' => [...StatsVocabulary::notes(), ...StatsVocabulary::dashboardNotes($productIds !== null)],
        ]);
    }

    /**
     * @param  array<string, string>  $filters  product, game
     * @return array<string, mixed>
     */
    public function games(FinanceDateRange $range, array $filters): array
    {
        $productIds = $this->productIdsFor($filters);
        $warnings = $this->freshen([$range]);
        $products = $this->products($productIds);
        $sums = $this->productDays($range, $productIds);
        $players = $this->playersBy($range, $productIds, 'a.product_id');
        $playersByBucket = $this->playersByBucket($range, $productIds, 'a.product_id');

        $totals = [];
        $series = [];
        foreach ($sums as $row) {
            $bucket = $range->bucketFor(CarbonImmutable::parse($row->date, config('app.timezone')));
            $this->add($totals[$row->product_id], $row);
            $this->add($series[$row->product_id][$bucket], $row);
        }

        $rows = [];
        foreach ($products as $product) {
            if (! isset($totals[$product->id]) && ! isset($players[$product->id])) {
                continue;
            }

            $list = [];
            foreach ($range->buckets() as $bucket) {
                $figures = $this->figures($series[$product->id][$bucket] ?? [], $product->revenue_model);
                $list[] = ['date' => $bucket, 'bets' => $figures['bets'], 'players' => $playersByBucket[$product->id][$bucket] ?? 0]
                    + array_intersect_key($figures, array_flip(['turnover', 'payouts', 'ggr', 'hold', 'rtp']));
            }

            $rows[] = $this->productRow($product, $totals[$product->id] ?? [], $players[$product->id] ?? 0) + ['series' => $list];
        }

        return $this->rounded([
            'range' => $this->rangeOf($range),
            'by_product' => $rows,
            'hourly_heatmap' => $this->heatmap($range, $filters, $productIds),
            'generated_at' => $this->generatedAt($range),
            'warnings' => $warnings,
            'notes' => [...StatsVocabulary::notes(), ...StatsVocabulary::gamesNotes()],
        ]);
    }

    /**
     * Money, players and the series of one window.
     *
     * @param  list<int>|null  $productIds
     * @return array{totals: array<string, mixed>, by_product: array<string, array<string, mixed>>, by_game: array<string, array<string, mixed>>, series: list<array<string, mixed>>}
     */
    private function block(FinanceDateRange $range, ?array $productIds): array
    {
        $platform = $productIds === null;
        $products = $this->products($productIds)->keyBy('id');
        $byProduct = [];
        $byGame = [];
        $byBucket = [];
        $byBucketGame = [];
        $all = [];

        foreach ($this->productDays($range, $productIds) as $row) {
            $product = $products[$row->product_id] ?? null;
            if ($product === null) {
                continue;
            }
            $bucket = $range->bucketFor(CarbonImmutable::parse($row->date, config('app.timezone')));

            $this->add($all, $row);
            $this->add($byProduct[$product->id], $row);
            $this->add($byGame[$product->game_id], $row);
            $this->add($byBucket[$bucket], $row);
            $this->add($byBucketGame[$product->game_id][$bucket], $row);
        }

        $playersByProduct = $this->playersBy($range, $productIds, 'a.product_id');
        $playersByGame = $this->playersBy($range, $productIds, 'a.game_id');
        $playersByBucket = $this->playersByBucket($range, $productIds, null);
        $playersByBucketGame = $this->playersByBucket($range, $productIds, 'a.game_id');
        $cash = $platform ? $this->platformDays($range) : collect();

        $cashByBucket = [];
        $cashTotals = ['deposits' => 0.0, 'withdrawals' => 0.0, 'net_cash' => 0.0, 'new_players' => 0, 'first_depositors' => 0];
        foreach ($cash as $row) {
            $bucket = $range->bucketFor(CarbonImmutable::parse($row->date, config('app.timezone')));
            foreach ($cashTotals as $key => $value) {
                $cashByBucket[$bucket][$key] = ($cashByBucket[$bucket][$key] ?? 0) + $row->{$key};
                $cashTotals[$key] += $row->{$key};
            }
        }

        $series = [];
        foreach ($range->buckets() as $bucket) {
            $figures = $this->figures($byBucket[$bucket] ?? [], null);
            $series[] = [
                'date' => $bucket,
                'ggr' => $figures['ggr'],
                'turnover' => $figures['turnover'],
                'net' => $figures['net'],
                'deposits' => $platform ? (float) ($cashByBucket[$bucket]['deposits'] ?? 0) : null,
                'withdrawals' => $platform ? (float) ($cashByBucket[$bucket]['withdrawals'] ?? 0) : null,
                'active_players' => $playersByBucket[0][$bucket] ?? 0,
                'new_players' => $platform ? (int) ($cashByBucket[$bucket]['new_players'] ?? 0) : null,
            ];
        }

        $productRows = [];
        foreach ($products as $product) {
            if (isset($byProduct[$product->id]) || isset($playersByProduct[$product->id])) {
                $productRows[$product->code] = $this->productRow($product, $byProduct[$product->id] ?? [], $playersByProduct[$product->id] ?? 0);
            }
        }

        $gameRows = [];
        foreach ($products->groupBy('game_id') as $gameId => $gameProducts) {
            if (! isset($byGame[$gameId]) && ! isset($playersByGame[$gameId])) {
                continue;
            }

            $gameSeries = [];
            foreach ($range->buckets() as $bucket) {
                $gameSeries[] = [
                    'date' => $bucket,
                    'ggr' => (float) ($byBucketGame[$gameId][$bucket]['ggr'] ?? 0),
                    'players' => $playersByBucketGame[$gameId][$bucket] ?? 0,
                ];
            }

            $game = $gameProducts->first()->game;
            $model = $gameProducts->pluck('revenue_model')->unique();
            $figures = $this->figures($byGame[$gameId] ?? [], $model->count() === 1 ? $model->first() : null);

            $gameRows[$game->code] = [
                'game' => $game->code,
                'products' => $gameProducts->pluck('code')->values()->all(),
                'bets' => $figures['bets'],
                'players' => $playersByGame[$gameId] ?? 0,
            ] + array_diff_key($figures, ['bets' => true]) + ['series' => $gameSeries];
        }

        return [
            'totals' => $this->figures($all, null) + [
                'deposits' => $platform ? $cashTotals['deposits'] : null,
                'withdrawals' => $platform ? $cashTotals['withdrawals'] : null,
                'net_cash' => $platform ? $cashTotals['net_cash'] : null,
                'active_players' => $this->activePlayers($range, $productIds),
                'new_players' => $platform ? $cashTotals['new_players'] : null,
                'first_depositors' => $platform ? $cashTotals['first_depositors'] : null,
                'depositing_players' => $platform ? $this->depositingPlayers($range) : null,
            ],
            'by_product' => $productRows,
            'by_game' => $gameRows,
            'series' => $series,
        ];
    }

    /**
     * @param  list<int>|null  $productIds
     * @return array<string, mixed>
     */
    private function playerBlock(FinanceDateRange $range, ?array $productIds): array
    {
        $end = $range->to->startOfDay();
        $window = fn (int $days) => $this->playerRows(new FinanceDateRange($end->subDays($days - 1), $range->to, FinanceDateRange::GROUP_BY_DAY, $range->excludeTest), $productIds)
            ->distinct()->count('a.customer_id');

        $active = $this->activePlayers($range, $productIds);
        $returning = $this->playerRows($range, $productIds)
            ->join('customers as c', 'c.id', '=', 'a.customer_id')
            ->where('c.created_at', '<', $range->from)
            ->distinct()
            ->count('a.customer_id');

        $crossGame = DB::query()
            ->fromSub($this->playerRows($range, $productIds)->selectRaw('a.customer_id, COUNT(DISTINCT a.game_id) as games')->groupBy('a.customer_id'), 'x')
            ->selectRaw('LEAST(x.games, 3) as games, COUNT(*) as players')
            ->groupByRaw('LEAST(x.games, 3)')
            ->pluck('players', 'games');

        return [
            'active' => ['day' => $window(1), 'week' => $window(7), 'month' => $window(30)],
            'new' => $active - $returning,
            'returning' => $returning,
            'cross_game' => ['1' => (int) ($crossGame[1] ?? 0), '2' => (int) ($crossGame[2] ?? 0), '3_plus' => (int) ($crossGame[3] ?? 0)],
        ];
    }

    /**
     * Product rollup rows per day of the window.
     *
     * @param  list<int>|null  $productIds
     * @return Collection<int, object>
     */
    private function productDays(FinanceDateRange $range, ?array $productIds): Collection
    {
        return DB::table('stats_daily_product as s')
            ->join('products as p', 'p.id', '=', 's.product_id')
            ->whereBetween('s.date', [$range->from->toDateString(), $range->to->toDateString()])
            ->where('s.excludes_test', $range->excludeTest)
            ->when($productIds !== null, fn (Builder $query) => $query->whereIn('s.product_id', $productIds ?: [0]))
            ->selectRaw("s.date, s.product_id, s.bets, s.voids, s.turnover, s.payouts, s.ggr, s.promotions, s.free_bet_value, s.winnings_tax, CASE WHEN p.revenue_model = 'house_banked' THEN s.turnover ELSE 0 END as house_banked_turnover")
            ->get();
    }

    /**
     * @return Collection<int, object>
     */
    private function platformDays(FinanceDateRange $range): Collection
    {
        return DB::table('stats_daily_platform')
            ->whereBetween('date', [$range->from->toDateString(), $range->to->toDateString()])
            ->where('excludes_test', $range->excludeTest)
            ->get();
    }

    private function depositingPlayers(FinanceDateRange $range): int
    {
        return DB::table('stats_daily_depositors')
            ->whereBetween('date', [$range->from->toDateString(), $range->to->toDateString()])
            ->where('deposits', '>', 0)
            ->when($range->excludeTest, fn (Builder $query) => $query->where('is_test', false))
            ->distinct()
            ->count('customer_id');
    }

    /**
     * Stakes and players per weekday and hour (Nairobi) over the window: the whole platform, one game or one
     * product, from the hourly rollup.
     *
     * @param  array<string, string>  $filters
     * @param  list<int>|null  $productIds
     * @return list<array{weekday: string, hour: int, stakes: float, players: int, avg_players: float}>
     */
    private function heatmap(FinanceDateRange $range, array $filters, ?array $productIds): array
    {
        [$scope, $scopeIds] = match (true) {
            ! empty($filters['product']) => ['product', $productIds ?: [0]],
            ! empty($filters['game']) => ['game', [(int) Game::where('code', $filters['game'])->value('id')]],
            default => ['all', [0]],
        };

        $cells = DB::table('stats_hourly_activity')
            ->whereBetween('date', [$range->from->toDateString(), $range->to->toDateString()])
            ->where('excludes_test', $range->excludeTest)
            ->where('scope', $scope)
            ->whereIn('scope_id', $scopeIds)
            ->selectRaw('WEEKDAY(date) as weekday, hour, SUM(stakes) as stakes, SUM(players) as players')
            ->groupByRaw('WEEKDAY(date), hour')
            ->get()
            ->keyBy(fn ($row) => $row->weekday.':'.$row->hour);

        $weekdays = array_fill(0, 7, 0);
        for ($day = $range->from->startOfDay(); $day->lessThanOrEqualTo($range->to); $day = $day->addDay()) {
            $weekdays[$day->dayOfWeekIso - 1]++;
        }

        $grid = [];
        foreach (self::WEEKDAYS as $index => $weekday) {
            for ($hour = 0; $hour < 24; $hour++) {
                $cell = $cells["{$index}:{$hour}"] ?? null;
                $players = (int) ($cell->players ?? 0);
                $grid[] = [
                    'weekday' => $weekday,
                    'hour' => $hour,
                    'stakes' => (float) ($cell->stakes ?? 0),
                    'players' => $players,
                    'avg_players' => $weekdays[$index] > 0 ? $players / $weekdays[$index] : 0.0,
                ];
            }
        }

        return $grid;
    }

    /**
     * @param  array<string, float|int>  $sums
     * @return array<string, mixed>
     */
    private function productRow(Product $product, array $sums, int $players): array
    {
        $figures = $this->figures($sums, $product->revenue_model);

        return [
            'product' => $product->code,
            'game' => $product->game?->code,
            'revenue_model' => $product->revenue_model,
            'bets' => $figures['bets'],
            'players' => $players,
        ] + array_diff_key($figures, ['bets' => true]);
    }

    /**
     * Derived figures from summed rollup columns. Rake products have no payouts or RTP; a mixed set
     * (totals, a game with both models) takes RTP over its house-banked turnover only.
     *
     * @param  array<string, float|int>  $sums
     * @return array<string, mixed>
     */
    private function figures(array $sums, ?string $revenueModel): array
    {
        $sum = fn (string $key) => (float) ($sums[$key] ?? 0);
        $rake = $revenueModel === Product::REVENUE_RAKE;
        $bets = (int) ($sums['bets'] ?? 0);

        return [
            'bets' => $bets,
            'voids' => (int) ($sums['voids'] ?? 0),
            'turnover' => $sum('turnover'),
            'payouts' => $rake ? null : $sum('payouts'),
            'ggr' => $sum('ggr'),
            'hold' => $this->percent($sum('ggr'), $sum('turnover')),
            'rtp' => $rake ? null : $this->percent($sum('payouts'), $sum('house_banked_turnover')),
            'avg_stake' => $bets > 0 ? $sum('turnover') / $bets : null,
            'free_bet_value' => $sum('free_bet_value'),
            'promotions' => $sum('promotions'),
            'net' => $sum('ggr') - $sum('promotions'),
            'winnings_tax_withheld' => $sum('winnings_tax'),
        ];
    }

    /**
     * @param  array<string, float|int>|null  $sums
     */
    private function add(?array &$sums, object $row): void
    {
        $sums ??= [];
        foreach (self::MONEY_FIGURES as $key) {
            $sums[$key] = ($sums[$key] ?? 0) + (float) $row->{$key};
        }
    }

    /**
     * A row with its comparison: `previous` holds the comparison window's figures and `delta_pct` the change
     * in percent (null when the previous figure is 0 or missing).
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>|null  $previous
     * @param  list<string>  $skip  Keys that are labels or have no comparison.
     * @return array<string, mixed>
     */
    private function compared(array $current, ?array $previous, array $skip = []): array
    {
        $figures = fn (?array $row) => array_diff_key($row ?? [], array_flip([...$skip, 'series']));
        $before = $figures($previous);
        $deltas = [];

        foreach ($figures($current) as $key => $value) {
            if (is_array($value)) {
                $deltas[$key] = $this->compared($value, $before[$key] ?? null)['delta_pct'];

                continue;
            }

            $old = $before[$key] ?? null;
            $deltas[$key] = is_numeric($value) && is_numeric($old) && round((float) $old, 2) != 0.0
                ? ($value - $old) / abs($old) * 100
                : null;
        }

        return $current + ['previous' => $previous === null ? null : $before, 'delta_pct' => $deltas];
    }

    private function percent(float $part, float $whole): ?float
    {
        return round($whole, 2) > 0 ? $part / $whole * 100 : null;
    }
}
