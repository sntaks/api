<?php

/*
|--------------------------------------------------------------------------
| Prediction markets
|--------------------------------------------------------------------------
|
| Defaults an admin can override per market. Money is in KES here and stored in minor units (cents);
| rates are fractions here and stored in basis points. Phase 1 is Kenya only, in KES.
|
*/

return [

    // The game whose game API key places and settles prediction wagers in-process
    'game_code' => env('PREDICT_GAME_CODE', 'predict'),

    'products' => [
        'pool' => env('PREDICT_POOL_PRODUCT', 'predict_pool'),
        // Imported markets: view-only in phase 1b, fixed odds from phase 2
        'reference' => env('PREDICT_REFERENCE_PRODUCT', 'predict_fixed'),
    ],

    'currency' => 'KES',

    // Phase 1: every customer is treated as being in this country (customers have no country yet)
    'default_country' => 'KE',

    'countries' => [
        'KE' => ['currency' => 'KES', 'timezone' => 'Africa/Nairobi'],
    ],

    // Categories our licence allows (confirmed 2026-11-10). Economy and crypto are not allowed.
    'categories' => ['sport', 'entertainment', 'politics'],

    'pool' => [
        'cut' => (float) env('PREDICT_POOL_CUT', 0.10),
        'seed' => (float) env('PREDICT_POOL_SEED', 5000),
        // A bet is refused when it would push its side's multiplier below this
        'min_multiplier' => (float) env('PREDICT_POOL_MIN_MULTIPLIER', 1.05),
    ],

    'limits' => [
        'min_stake' => (float) env('PREDICT_MIN_STAKE', 10),
        'max_stake' => (float) env('PREDICT_MAX_STAKE', 5000),
        // A winning bet is paid at most this; the house keeps the rest
        'max_payout' => (float) env('PREDICT_MAX_PAYOUT', 250000),
    ],

    // Points returned by the history endpoint at most
    'history_points' => 200,

    'queue' => env('PREDICT_QUEUE', 'default'),

    /*
    | Polymarket, read-only and without authentication: events and markets from the Gamma API, reference prices
    | from the CLOB API. Used under licence (confirmed 2026-11-10). Off until PREDICT_POLYMARKET_ENABLED is set.
    */
    'polymarket' => [
        'enabled' => (bool) env('PREDICT_POLYMARKET_ENABLED', false),
        'gamma_url' => env('PREDICT_POLYMARKET_GAMMA_URL', 'https://gamma-api.polymarket.com'),
        'clob_url' => env('PREDICT_POLYMARKET_CLOB_URL', 'https://clob.polymarket.com'),
        'connect_timeout' => 3,
        'timeout' => 5,
        'retries' => 3,
        'retry_base_ms' => 200,

        // Our category => the Polymarket tag slugs imported under it
        'tags' => [
            'sport' => ['sports'],
            'politics' => ['politics'],
            'entertainment' => ['pop-culture'],
        ],
        'page_size' => 100,
        'max_pages_per_tag' => (int) env('PREDICT_POLYMARKET_MAX_PAGES', 5),
        // Markets with less trading volume (USD) than this are not imported
        'min_volume' => (float) env('PREDICT_POLYMARKET_MIN_VOLUME', 10000),
        // Imported markets must be resolved by their end date plus this
        'resolution_days' => 7,

        // A reference price older than this is stale: the market is suspended
        'stale_after_seconds' => (int) env('PREDICT_POLYMARKET_STALE_AFTER', 120),
        'midpoint_batch' => 100,
        // History keeps a point when the price moves this much, or at least every snapshot_every_seconds
        'snapshot_min_move' => 0.005,
        'snapshot_every_seconds' => 300,
        'snapshot_days' => 30,

        // Consecutive failed calls that open the circuit, and how long it stays open
        'circuit_failures' => 5,
        'circuit_open_seconds' => 60,

        'attribution' => 'Reference prices from Polymarket',
    ],

];
