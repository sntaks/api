<?php

/*
|--------------------------------------------------------------------------
| Responsible gambling (decisions D12-D18 in the multi-game wallet plan)
|--------------------------------------------------------------------------
|
| Platform-wide: one break or limit covers every game. Amounts are whole Kenya shillings.
|
*/

return [

    /*
     * The breaks a player can take, as ISO 8601 durations. A break can be extended, never shortened or ended
     * early, by anyone. During one the player may log in and withdraw, but not deposit, stake or launch a game.
     */
    'exclusions' => [
        'cool_off' => ['24h' => 'PT24H', '7d' => 'P7D', '30d' => 'P30D'],
        'self_exclusion' => ['6m' => 'P6M', '1y' => 'P1Y', '5y' => 'P5Y'],
    ],

    /*
     * Deposit limits per rolling window, in hours. A cut applies at once; a raise or a removal waits.
     */
    'deposit_limit_periods' => ['day' => 24, 'week' => 168, 'month' => 720],
    'limit_raise_delay_hours' => 24,
    'limit_min' => 10,
    'limit_max' => 1_000_000,

    /*
     * An STK push counts against the limits for this long, before its money arrives through the paybill.
     */
    'stk_pending_minutes' => 3,

    /*
     * M-Pesa withdrawals (main and referral wallets).
     */
    'withdrawal_min' => 50,
    'withdrawal_max' => 150_000,
    'withdrawals_per_hour' => 5,

    /*
     * Withdrawals from this amount wait for approval (P4b).
     */
    'approval_threshold' => 20_000,

];
