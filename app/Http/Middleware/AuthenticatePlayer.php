<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Models\Customer;
use App\Services\PlayerTokenService;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class AuthenticatePlayer
{
    /**
     * A game server acting for a player: the calling key must be a game key (so it runs behind
     * apikey.checker) and the Bearer token a live player token for that same game. Every token problem
     * gets the same answer, so the response cannot be used to probe tokens. The player is kept on the
     * request as `player`.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var ApiKey|null $apiKey */
        $apiKey = $request->attributes->get('api_key');

        if (! $apiKey?->isGameKey() || ! $apiKey->game) {
            return response()->json(['message' => 'This route needs a game API key', 'code' => 'game_key_required'], 403);
        }

        $token = $request->bearerToken() ? PersonalAccessToken::findToken($request->bearerToken()) : null;

        if (! $token
            || $token->tokenable_type !== Customer::class
            // Every player token is issued with an expiry; one without is not a player token.
            || ! $token->expires_at
            || $token->expires_at->isPast()
            // Exact match: a token with the '*' ability is not a player token for any game.
            || ! in_array(PlayerTokenService::abilityFor($apiKey->game->code), $token->abilities ?? [], true)) {
            return response()->json(['message' => 'Invalid or expired player token', 'code' => 'invalid_player_token'], 401);
        }

        $customer = Customer::withTrashed()->find($token->tokenable_id);

        if (! $customer) {
            return response()->json(['message' => 'Invalid or expired player token', 'code' => 'invalid_player_token'], 401);
        }

        if ($customer->trashed() || (int) $customer->status !== 1) {
            return response()->json(['message' => 'This player cannot play', 'code' => 'player_inactive'], 403);
        }

        $request->attributes->set('player', $customer);

        return $next($request);
    }
}
