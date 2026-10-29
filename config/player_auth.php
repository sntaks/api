<?php

return [

    /*
     * How long a player token (issued to a game when it redeems a launch token) stays valid.
     */
    'token_ttl_hours' => (int) env('PLAYER_TOKEN_TTL_HOURS', 12),

];
