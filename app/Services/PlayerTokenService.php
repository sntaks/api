<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Game;
use Laravel\Sanctum\NewAccessToken;

/**
 * Player tokens: Sanctum personal access tokens on a customer, each good for one game only (ability
 * game:{code}) and for config('player_auth.token_ttl_hours'). The API never sees a player's password;
 * a game gets a token by redeeming the launch token kadi issued for a logged-in player.
 */
class PlayerTokenService
{
    public function issue(Customer $customer, Game $game): NewAccessToken
    {
        return $customer->createToken(
            "player:{$game->code}",
            [self::abilityFor($game->code)],
            now()->addHours(config('player_auth.token_ttl_hours')),
        );
    }

    /**
     * On logout or suspension: every game the player is in loses them at once.
     */
    public function revokeAll(Customer $customer): int
    {
        return $customer->tokens()->delete();
    }

    public static function abilityFor(string $gameCode): string
    {
        return "game:{$gameCode}";
    }
}
