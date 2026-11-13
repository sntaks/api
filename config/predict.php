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
        'lmsr' => env('PREDICT_LMSR_PRODUCT', 'predict_lmsr'),
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

    // LMSR market maker for custom markets (decided 2026-11-12). The fee is stored as the market's cut
    'lmsr' => [
        'liquidity' => (float) env('PREDICT_LMSR_LIQUIDITY', 5000),
        'fee' => (float) env('PREDICT_LMSR_FEE', 0.10),
    ],

    // Fixed odds on imported markets (decided 2026-11-12)
    'fixed' => [
        'margin' => (float) env('PREDICT_FIXED_MARGIN', 0.05),
        // The most the house may lose on one side: payouts on the side minus all stakes on the market (KES)
        'max_liability' => (float) env('PREDICT_FIXED_MAX_LIABILITY', 500000),
        // The most one customer may stake in total on one market (KES)
        'max_customer_stake' => (float) env('PREDICT_FIXED_MAX_CUSTOMER_STAKE', 20000),
        // Margin added as a side's liability fills its cap: [share of the cap reached, basis points added].
        // Decided 2026-11-12: +2% past half the cap, +5% past 80%
        'margin_ladder' => [[0.5, 200], [0.8, 500]],
        'max_offer' => 0.99,
        'quote_seconds' => 10,
        // Re-quote when our offer price moved more than this fraction against the house
        'requote_tolerance' => 0.01,
        // Betting guards; a failing one refuses bets and the price sync suspends the market
        'max_price_age_seconds' => (int) env('PREDICT_FIXED_MAX_PRICE_AGE', 45),
        // While the WebSocket price stream is live (decided 2026-11-12)
        'max_price_age_stream_seconds' => (int) env('PREDICT_FIXED_MAX_PRICE_AGE_STREAM', 10),
        'max_spread' => (float) env('PREDICT_FIXED_MAX_SPREAD', 0.05),
        // USD resting on each side's asks within depth_band of the best ask. Set 2026-11-12 from a live sample:
        // USD 5,000 within 0.02 left ~8% of high-volume markets bettable, USD 1,000 within 0.05 ~29%
        'min_depth_usd' => (float) env('PREDICT_FIXED_MIN_DEPTH_USD', 1000),
        'depth_band' => (float) env('PREDICT_FIXED_DEPTH_BAND', 0.05),
        // Without a game start time, betting stops this long before the close time
        'cutoff_minutes' => 15,
    ],

    // Insider and abuse rules (decided 2026-11-12): each fires a RiskEvent for GMS, the bet still goes through
    'risk' => [
        'late_large' => ['min_stake' => 2000, 'minutes_before_cutoff' => 30],
        'fresh_account' => ['max_age_days' => 7, 'min_market_stake' => 2000],
        'cluster' => ['min_customers' => 5, 'min_stake' => 1000, 'window_minutes' => 10],
        'sharp_winner' => ['min_settled' => 10, 'min_win_rate' => 0.8],
    ],

    'resolution' => [
        // Pay out a confirmed Polymarket result without an admin (off: an admin confirms each proposal)
        'auto_settle' => (bool) env('PREDICT_AUTO_SETTLE', false),
    ],

    'limits' => [
        'min_stake' => (float) env('PREDICT_MIN_STAKE', 10),
        'max_stake' => (float) env('PREDICT_MAX_STAKE', 5000),
        // A winning bet is paid at most this; the house keeps the rest
        'max_payout' => (float) env('PREDICT_MAX_PAYOUT', 250000),
    ],

    // Points returned by the history endpoint at most
    'history_points' => 200,

    // The polling feed (GET /predict/updates): markets per answer, and how far before `since` it looks back so a
    // change committed while the previous answer was being built is not lost
    'updates_page_size' => 500,
    'updates_overlap_seconds' => (int) env('PREDICT_UPDATES_OVERLAP', 3),

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

        // How new imports are priced (decided 2026-11-13): `pool` uses Polymarket only as the market source and
        // prices with our pool and a notional house seed; `reference` follows Polymarket's live price at fixed odds
        'engine' => env('PREDICT_POLYMARKET_ENGINE', 'pool'),
        'seed' => (float) env('PREDICT_POLYMARKET_SEED', 1000),
        // A pool import opens at Polymarket's YES price, read once at publish and kept within these bounds
        'opening_min' => 0.05,
        'opening_max' => 0.95,

        // A reference price older than this is stale: the market is suspended
        'stale_after_seconds' => (int) env('PREDICT_POLYMARKET_STALE_AFTER', 120),
        'midpoint_batch' => 100,
        'book_batch' => 50,
        'lookup_batch' => 100,
        // History keeps a point when the price moves this much, or at least every snapshot_every_seconds
        'snapshot_min_move' => 0.005,
        'snapshot_every_seconds' => 300,
        'snapshot_days' => 30,

        // Consecutive failed calls that open the circuit, and how long it stays open
        // WebSocket market channel (predict:price-stream under Supervisor)
        'stream_url' => env('PREDICT_POLYMARKET_STREAM_URL', 'wss://ws-subscriptions-clob.polymarket.com/ws/market'),
        'stream_resubscribe_seconds' => 30,
        // The stream counts as live while its heartbeat is younger than this
        'stream_heartbeat_seconds' => 15,
        // While the import is off the worker idles this long before exiting, so Supervisor does not spin
        'stream_idle_seconds' => 60,

        'circuit_failures' => 5,
        'circuit_open_seconds' => 60,

        'attribution' => 'Reference prices from Polymarket',
    ],

];
