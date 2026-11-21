<?php

use App\Models\Game;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Opt-in (excluded from the default run in phpunit.xml): php artisan test --group=perf
 *
 * Seeds the rollups that a year of 1M wagers across 10 products leaves behind (10 wagers per player, product and day,
 * so 100,000 player-product-day rows, plus the product, hourly and platform rows) and requires /stats/dashboard
 * for this_year to answer in under a second from them.
 */
it('answers this_year from the rollups in under a second', function () {
    config(['stats.today_refresh_seconds' => 86400]);
    $headers = apiHeaders(createApiKey('test-stats-perf-key')->key);

    $games = collect(range(1, 5))->map(fn (int $i) => Game::factory()->create(['code' => "perf_game_{$i}"]));
    $products = collect(range(1, 10))->map(fn (int $i) => Product::factory()->create(['game_id' => $games[intdiv($i - 1, 2)]->id, 'code' => "perf_product_{$i}"]));

    $from = CarbonImmutable::today(config('app.timezone'))->startOfYear();
    $days = (int) $from->diffInDays(CarbonImmutable::today(config('app.timezone'))) + 1;
    $wagersPerRow = 10;
    $playerRows = intdiv(1_000_000, $wagersPerRow);
    $start = $from->toDateString();

    DB::statement('SET SESSION cte_max_recursion_depth = 1000000');

    // One row per product per day per test variant
    foreach ($products as $index => $product) {
        DB::statement('INSERT INTO stats_daily_product (date, product_id, excludes_test, bets, voids, turnover, payouts, ggr, promotions, free_bet_value, winnings_tax)
            WITH RECURSIVE d(n) AS (SELECT 0 UNION ALL SELECT n + 1 FROM d WHERE n < ?)
            SELECT DATE_ADD(?, INTERVAL n DAY), ?, v.flag, 300, 2, 30000, 27000, 3000, 100, 0, 50 FROM d CROSS JOIN (SELECT 0 AS flag UNION ALL SELECT 1) v', [$days - 1, $start, $product->id]);
    }

    // Player-product-day rows: 1M wagers / 30 per row, spread over the days and products, 20,000 customers
    $ids = $products->pluck('id')->implode(',');
    $games = $products->pluck('game_id')->implode(',');
    DB::statement("INSERT INTO stats_daily_player_product (date, product_id, customer_id, game_id, is_test, entries, stakes)
        WITH RECURSIVE s(n) AS (SELECT 0 UNION ALL SELECT n + 1 FROM s WHERE n < ?)
        SELECT DATE_ADD(?, INTERVAL (n MOD ?) DAY), ELT(1 + FLOOR(n / ?) MOD 10, {$ids}), 100000 + MOD(FLOOR(n / (? * 10)) * 37 + (n MOD ?) * 7 + (FLOOR(n / ?) MOD 10) * 13, 20000),
            ELT(1 + FLOOR(n / ?) MOD 10, {$games}), 0, ?, 3000 FROM s",
        [$playerRows - 1, $start, $days, $days, $days, $days, $days, $days, $wagersPerRow]);

    // Hourly cells for the platform, each game and each product, both variants
    DB::statement("INSERT INTO stats_hourly_activity (date, hour, scope, scope_id, excludes_test, stakes, players)
        WITH RECURSIVE d(n) AS (SELECT 0 UNION ALL SELECT n + 1 FROM d WHERE n < ?), h(n) AS (SELECT 0 UNION ALL SELECT n + 1 FROM h WHERE n < 23)
        SELECT DATE_ADD(?, INTERVAL d.n DAY), h.n, s.scope, s.scope_id, v.flag, 1000, 40 FROM d CROSS JOIN h
        CROSS JOIN (SELECT 'all' AS scope, 0 AS scope_id UNION ALL SELECT 'product', id FROM products WHERE code LIKE 'perf_product_%') s
        CROSS JOIN (SELECT 0 AS flag UNION ALL SELECT 1) v", [$days - 1, $start]);

    DB::statement("INSERT INTO stats_daily_players (date, excludes_test, scope, scope_id, players)
        WITH RECURSIVE d(n) AS (SELECT 0 UNION ALL SELECT n + 1 FROM d WHERE n < ?)
        SELECT DATE_ADD(?, INTERVAL d.n DAY), v.flag, s.scope, s.scope_id, 100 FROM d
        CROSS JOIN (SELECT 'all' AS scope, 0 AS scope_id UNION ALL SELECT 'product', id FROM products WHERE code LIKE 'perf_product_%'
            UNION ALL SELECT 'game', id FROM games WHERE code LIKE 'perf_game_%') s
        CROSS JOIN (SELECT 0 AS flag UNION ALL SELECT 1) v", [$days - 1, $start]);

    DB::statement('INSERT INTO stats_daily_platform (date, excludes_test, deposits, withdrawals, net_cash, new_players, first_depositors)
        WITH RECURSIVE d(n) AS (SELECT 0 UNION ALL SELECT n + 1 FROM d WHERE n < ?)
        SELECT DATE_ADD(?, INTERVAL n DAY), v.flag, 50000, 30000, 20000, 60, 40 FROM d CROSS JOIN (SELECT 0 AS flag UNION ALL SELECT 1) v', [$days - 1, $start]);

    // Every day of the year, the compare window and the 30-day window counts as built
    DB::statement('INSERT INTO stats_rollup_days (date, built_at)
        WITH RECURSIVE d(n) AS (SELECT 0 UNION ALL SELECT n + 1 FROM d WHERE n < ?)
        SELECT DATE_ADD(?, INTERVAL n DAY), NOW() FROM d', [$days * 2 + 30, $from->subDays($days + 30)->toDateString()]);

    expect(DB::table('stats_daily_player_product')->sum('entries'))->toBeGreaterThanOrEqual(990_000);

    $this->getJson('/api/v1/stats/dashboard?period=this_year', $headers)->assertOk(); // warm the connection and the autoloader
    $started = hrtime(true);
    $dashboard = $this->getJson('/api/v1/stats/dashboard?period=this_year', $headers)->assertOk()->json('data');
    $seconds = (hrtime(true) - $started) / 1e9;

    expect($dashboard['warnings'])->toBe([])
        ->and($dashboard['by_product'])->toHaveCount(10)
        ->and($seconds)->toBeLessThan(1.0);
})->group('perf');
