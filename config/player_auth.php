<?php

return [

    /*
     * How long a player token (issued to a game when it redeems a launch token) stays valid.
     */
    'token_ttl_hours' => (int) env('PLAYER_TOKEN_TTL_HOURS', 12),

    /*
     * How long a launch token kadi issues stays redeemable. It only has to survive the redirect to the game.
     */
    'launch_token_ttl_seconds' => (int) env('LAUNCH_TOKEN_TTL_SECONDS', 120),

];
