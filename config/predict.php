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

];
