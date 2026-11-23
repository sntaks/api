<?php

/*
 * Kadi Jet, the in-house crash game. Defaults come from the crash server's config/aviator.php. Stake limits,
 * max payout and the winnings tax rate are not here: they are the jet_classic product's (edited in GMS).
 */
return [
    'game_code' => env('JET_GAME_CODE', 'jet'),

    'product_code' => env('JET_PRODUCT_CODE', 'jet_classic'),

    'currency' => 'KES',

    // RTP is an owner decision: never editable from GMS. Every round freezes the value it was created with.
    'house_edge' => (float) env('JET_HOUSE_EDGE', 0.03),

    'max_multiplier' => (float) env('JET_MAX_MULTIPLIER', 5000),

    'betting_window_seconds' => (int) env('JET_BETTING_WINDOW_SECONDS', 25),

    // How long a finished round stays on screen before the next betting window opens.
    'post_round_pause_seconds' => (int) env('JET_POST_ROUND_PAUSE_SECONDS', 15),

    // M(t) = e^(k·t)
    'acceleration_k' => (float) env('JET_ACCELERATION_K', 0.25),

    // How long the game loop's database lease lasts without a renewal.
    'lease_seconds' => 15,

    // A launch's one-time code must be exchanged for the player token within this time
    'session_code_ttl_seconds' => 60,

    // Public WebSocket settings the browser connects with (through the jet.kadi.online proxy)
    'realtime' => [
        'host' => env('JET_REALTIME_HOST', 'jet.kadi.online'),
        'port' => (int) env('JET_REALTIME_PORT', 443),
        'scheme' => env('JET_REALTIME_SCHEME', 'https'),
    ],

    // Operation results are kept this long; the key's identity is kept for good.
    'operation_ttl_days' => 7,
];
