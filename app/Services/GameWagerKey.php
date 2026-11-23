<?php

namespace App\Services;

use App\Models\ApiKey;
use App\Models\Game;
use RuntimeException;

/**
 * The game key an in-process game (Predict, Kadi Jet) posts its wagers with: the oldest active game-scope key
 * of that game. It never leaves the server.
 */
class GameWagerKey
{
    public function for(string $gameCode): ApiKey
    {
        $gameId = Game::idFor($gameCode);
        $key = $gameId ? ApiKey::where('game_id', $gameId)->where('scope', ApiKey::SCOPE_GAME)->where('is_active', true)->orderBy('id')->first() : null;

        if (! $key) {
            throw new RuntimeException("Game {$gameCode} needs an active game API key to post wagers");
        }

        return $key;
    }
}
