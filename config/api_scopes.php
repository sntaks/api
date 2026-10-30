<?php

/*
|--------------------------------------------------------------------------
| Routes each restricted key scope may call
|--------------------------------------------------------------------------
|
| "{METHOD} {route uri}" exactly as registered (see php artisan route:list). CheckApiKey refuses a site or
| game key on any other route with 403 route_not_allowed. Full and shop keys are not listed here: full
| keys reach every route except the shop system's, shop keys only /v1/shop/*. A route added to the API is
| refused to site and game keys until it is added here.
|
*/

return [

    /*
     * The kadi site: the routes app/Services/KadiApiService.php calls (checked against kadi 05e7ad6,
     * 2026-10-17), the lobby, and the bet shop cash withdrawals a player asks for on kadi.
     */
    'site' => [
        // Signup, profile and history
        'POST api/v1/customers',
        'GET api/v1/customers/{encryptedIdentifier}',
        'PUT api/v1/customers/{encryptedIdentifier}',
        'POST api/v1/customers/{encryptedIdentifier}/verified',
        'POST api/v1/customers/transactions/{encryptedIdentifier}',
        'GET api/v1/customers/played/recent/{encryptedIdentifier}',
        'POST api/v1/stats/customers/played',

        // Promotions and referrals
        'GET api/v1/customers/{encryptedIdentifier}/promotions',
        'GET api/v1/promo-codes/lookup',
        'GET api/v1/referrals/lookup',
        'GET api/v1/customers/{encryptedIdentifier}/referral-code',
        'PUT api/v1/customers/{encryptedIdentifier}/referral-code',
        'GET api/v1/customers/{encryptedIdentifier}/referrals',
        'GET api/v1/customers/{encryptedIdentifier}/referrals/stats',
        'GET api/v1/customers/{encryptedIdentifier}/referral-wallet',
        'GET api/v1/customers/{encryptedIdentifier}/referral-wallet/withdrawals',
        'POST api/v1/customers/{encryptedIdentifier}/referral-wallet/withdraw',

        // Cashier: STK top-up, gift and emoji purchase, M-Pesa withdrawal
        'POST api/v1/deposits/{encryptedIdentifier}',
        'POST api/v1/load/{encryptedIdentifier}',
        'POST api/v1/withdraw/{encryptedIdentifier}',
        'POST api/v1/customers/{encryptedIdentifier}/withdrawals/{id}/cancel',

        // Cash withdrawal at a bet shop, asked for by the player
        'GET api/v1/customers/{encryptedIdentifier}/shop-withdrawals',
        'POST api/v1/customers/{encryptedIdentifier}/shop-withdrawals',
        'POST api/v1/customers/{encryptedIdentifier}/shop-withdrawals/{withdrawalId}/cancel',

        // Responsible gambling: the player's state, and starting a break
        'GET api/v1/customers/{encryptedIdentifier}/responsible-gambling',
        'POST api/v1/customers/{encryptedIdentifier}/exclusion',
        'PUT api/v1/customers/{encryptedIdentifier}/deposit-limits/{period}',

        // Complaints
        'GET api/v1/complaints',
        'POST api/v1/complaints',

        // Lobby and game launch
        'GET api/v1/games',
        'POST api/v1/customers/{encryptedIdentifier}/launch-tokens',
        'POST api/v1/customers/{encryptedIdentifier}/player-tokens/revoke',
    ],

    /*
     * Game servers: start a player session from a launch token. The wager API (P5) adds its routes here.
     */
    'game' => [
        'POST api/v1/launch-tokens/redeem',
        'GET api/v1/me',
        'GET api/v1/me/balance',
        'POST api/v1/wagers',
        'POST api/v1/wagers/{reference}/settle',
        'POST api/v1/wagers/{reference}/reverse',
    ],

];
