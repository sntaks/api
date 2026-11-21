<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Stats dashboard rollups
    |--------------------------------------------------------------------------
    |
    | /stats/dashboard and /stats/games read daily rollup tables (php artisan stats:rebuild).
    |
    | today_refresh_seconds  a request whose range includes today rebuilds today's rollup first when it
    |                        is older than this, so today is never more than this many seconds behind.
    | on_demand_days         earlier days with no complete rollup that a request builds itself; any
    |                        more are reported in `warnings` (run stats:rebuild for them).
    | rate_limit_per_minute  the stats-dashboard limiter, per key (times the key's rate multiplier).
    |
    */

    'today_refresh_seconds' => (int) env('STATS_TODAY_REFRESH_SECONDS', 60),

    'on_demand_days' => (int) env('STATS_ON_DEMAND_DAYS', 3),

    'rate_limit_per_minute' => (int) env('STATS_DASHBOARD_RATE_LIMIT', 60),

    'liability' => [
        // Largest open positions listed by /stats/liability
        'top_positions' => 10,
    ],

];
