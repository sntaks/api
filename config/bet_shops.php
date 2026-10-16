<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Shop float purchases
    |--------------------------------------------------------------------------
    |
    | A shop owner buys float by paying the paybill with bill ref
    | SHOP-<shop_no>. While M-Pesa callbacks are not authenticated (anyone can
    | post a forged C2B confirmation), these payments are left unmatched and
    | credited by hand with POST /deposits/{id}/shop-float after checking the
    | M-Pesa statement. Turn auto_credit_float on only once callback
    | authentication is enforced.
    |
    */

    'auto_credit_float' => (bool) env('BET_SHOPS_AUTO_CREDIT_FLOAT', false),

    'bill_ref_prefix' => 'SHOP-',

    /*
    |--------------------------------------------------------------------------
    | Cash loads
    |--------------------------------------------------------------------------
    |
    | Whole shillings per load, and the most one player may be loaded across
    | every shop in a day (app timezone). Reversed loads do not count.
    |
    */

    'loads' => [
        'min_amount' => (int) env('BET_SHOPS_LOAD_MIN', 50),
        'max_amount' => (int) env('BET_SHOPS_LOAD_MAX', 20000),
        'daily_limit_per_player' => (int) env('BET_SHOPS_LOAD_DAILY_LIMIT', 50000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Shop system rate limits (per shop API key, per minute)
    |--------------------------------------------------------------------------
    */

    'rate_limits' => [
        'requests' => 60,
        'player_lookups' => 30,
    ],

];
