<?php

namespace App\Services\Stats;

use App\Models\Product;
use App\Services\FinanceDateRange;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reading the stats rollups: player-day rows, distinct player counts, freshness and formatting. The using
 * class has a StatsRollupService in $this->rollups.
 */
trait ReadsStatsRollups
{
    /** @var array<string, int> */
    private array $activePlayers = [];

    /**
     * Distinct players in the window, counted once per window.
     *
     * @param  list<int>|null  $productIds
     */
    private function activePlayers(FinanceDateRange $range, ?array $productIds): int
    {
        $key = implode(':', [$range->from->toDateString(), $range->to->toDateString(), (int) $range->excludeTest, implode(',', $productIds ?? ['all'])]);

        return $this->activePlayers[$key] ??= $this->playerRows($range, $productIds)->distinct()->count('a.customer_id');
    }

    /**
     * Player-day rows of the window (one per player, product and day they played).
     *
     * @param  list<int>|null  $productIds
     */
    private function playerRows(FinanceDateRange $range, ?array $productIds): Builder
    {
        return DB::table('stats_daily_player_product as a')
            ->whereBetween('a.date', [$range->from->toDateString(), $range->to->toDateString()])
            ->when($range->excludeTest, fn (Builder $query) => $query->where('a.is_test', false))
            ->when($productIds !== null, fn (Builder $query) => $query->whereIn('a.product_id', $productIds ?: [0]));
    }

    /**
     * Distinct players per product or game.
     *
     * @param  list<int>|null  $productIds
     * @return array<int, int>
     */
    private function playersBy(FinanceDateRange $range, ?array $productIds, string $column): array
    {
        return $this->playerRows($range, $productIds)
            ->selectRaw("{$column} as id, COUNT(DISTINCT a.customer_id) as players")
            ->groupBy($column)
            ->pluck('players', 'id')
            ->map(fn ($players) => (int) $players)
            ->all();
    }

    /**
     * Distinct players per bucket (day, week or month), in all (key 0) or per product or game.
     *
     * @param  list<int>|null  $productIds
     * @return array<int, array<string, int>>
     */
    private function playersByBucket(FinanceDateRange $range, ?array $productIds, ?string $column): array
    {
        if ($range->groupBy === FinanceDateRange::GROUP_BY_DAY) {
            return $this->storedDailyPlayers($range, $productIds, $column);
        }

        $bucket = match ($range->groupBy) {
            FinanceDateRange::GROUP_BY_WEEK => "DATE_FORMAT(DATE_SUB(a.date, INTERVAL WEEKDAY(a.date) DAY), '%Y-%m-%d')",
            FinanceDateRange::GROUP_BY_MONTH => "DATE_FORMAT(a.date, '%Y-%m')",
            default => "DATE_FORMAT(a.date, '%Y-%m-%d')",
        };
        $id = $column ?? '0';

        $result = [];
        $rows = $this->playerRows($range, $productIds)
            ->selectRaw("{$id} as id, {$bucket} as bucket, COUNT(DISTINCT a.customer_id) as players")
            ->groupByRaw($column === null ? $bucket : "{$column}, {$bucket}")
            ->get();

        foreach ($rows as $row) {
            $result[(int) $row->id][$row->bucket] = (int) $row->players;
        }

        return $result;
    }

    /**
     * Distinct players per day from stats_daily_players, in all (key 0) or per product or game. A product or game
     * filter always selects one product or one whole game, so the matching stored count is exact.
     *
     * @param  list<int>|null  $productIds
     * @return array<int, array<string, int>>
     */
    private function storedDailyPlayers(FinanceDateRange $range, ?array $productIds, ?string $column): array
    {
        $gameOf = Product::query()->when($productIds !== null, fn ($query) => $query->whereKey($productIds ?: [0]))->pluck('game_id', 'id');

        [$scope, $scopeIds] = match (true) {
            $column === 'a.product_id' => ['product', $productIds],
            $productIds === null => [$column === 'a.game_id' ? 'game' : 'all', null],
            count($productIds) === 1 => ['product', $productIds],
            default => ['game', $gameOf->unique()->values()->all()],
        };

        $rows = DB::table('stats_daily_players')
            ->whereBetween('date', [$range->from->toDateString(), $range->to->toDateString()])
            ->where('excludes_test', $range->excludeTest)
            ->where('scope', $scope)
            ->when($scopeIds !== null, fn (Builder $query) => $query->whereIn('scope_id', $scopeIds ?: [0]))
            ->get(['date', 'scope_id', 'players']);

        $result = [];
        foreach ($rows as $row) {
            $id = match (true) {
                $column === null => 0,
                $column === 'a.game_id' && $scope === 'product' => (int) $gameOf[$row->scope_id],
                default => (int) $row->scope_id,
            };
            $result[$id][CarbonImmutable::parse($row->date)->toDateString()] = (int) $row->players;
        }

        return $result;
    }

    /**
     * @param  list<int>|null  $productIds
     * @return Collection<int, Product>
     */
    private function products(?array $productIds): Collection
    {
        return Product::with('game')
            ->when($productIds !== null, fn ($query) => $query->whereKey($productIds ?: [0]))
            ->get()
            ->sortBy(fn (Product $product) => [$product->game?->sort_order ?? PHP_INT_MAX, $product->code])
            ->values();
    }

    /**
     * Make each window's rollups current and return the warnings for days that could not be built now.
     *
     * @param  list<FinanceDateRange>  $ranges
     * @return list<string>
     */
    private function freshen(array $ranges): array
    {
        $missing = [];
        foreach ($ranges as $range) {
            $missing = [...$missing, ...$this->rollups->ensureFresh($range)];
        }
        $missing = array_values(array_unique($missing));
        sort($missing);

        return $missing === [] ? [] : [
            count($missing).' day(s) have no complete rollup yet and count as zero: '.implode(', ', array_slice($missing, 0, 10))
            .(count($missing) > 10 ? ', …' : '').'. Run php artisan stats:rebuild for them.',
        ];
    }

    private function generatedAt(FinanceDateRange $range): ?string
    {
        $at = $this->rollups->builtAt($range);

        return $at?->toIso8601String();
    }

    /**
     * @return array{from: string, to: string, timezone: string}
     */
    private function rangeOf(FinanceDateRange $range): array
    {
        return ['from' => $range->from->toDateString(), 'to' => $range->to->toDateString(), 'timezone' => config('app.timezone')];
    }

    /**
     * Round every float to cents.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function rounded(array $values): array
    {
        return array_map(fn ($value) => match (true) {
            is_array($value) => $this->rounded($value),
            is_float($value) => round($value, 2),
            default => $value,
        }, $values);
    }
}
