<?php

namespace App\Http\Middleware;

use App\Domain\Jet\JetException;
use App\Models\Customer;
use App\Services\PlayerTokenService;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Jet browser's own auth (no API key): a Bearer player token for game `jet` only. `jet.player:optional`
 * lets anonymous reads through, but a bearer that is sent must still be valid (M1). Every token problem gets
 * the same answer. The player is kept on the request as `jet_player`.
 */
class AuthenticateJetPlayer
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $mode = 'required'): Response
    {
        $bearer = $request->bearerToken();

        if (! $bearer) {
            if ($mode === 'optional') {
                return $next($request);
            }

            throw new JetException('UNAUTHENTICATED', 401);
        }

        $request->attributes->set('jet_player', self::playerFor($bearer));

        return $next($request);
    }

    /**
     * The active customer behind a live `game:jet` token, for routes and channel auth.
     *
     * @throws JetException
     */
    public static function playerFor(string $bearer): Customer
    {
        $token = PersonalAccessToken::findToken($bearer);
        $ability = PlayerTokenService::abilityFor((string) config('jet.game_code'));

        if (! $token
            || $token->tokenable_type !== Customer::class
            || ! $token->expires_at
            || $token->expires_at->isPast()
            || ! in_array($ability, $token->abilities ?? [], true)) {
            throw new JetException('UNAUTHENTICATED', 401);
        }

        $customer = Customer::withTrashed()->find($token->tokenable_id);

        if (! $customer) {
            throw new JetException('UNAUTHENTICATED', 401);
        }

        if ($customer->trashed() || (int) $customer->status !== 1) {
            throw new JetException('PLAYER_DISABLED', 403);
        }

        return $customer;
    }
}
