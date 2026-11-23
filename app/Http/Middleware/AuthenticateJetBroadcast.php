<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Private channel auth for the Jet client (POST /broadcasting/auth through the jet.kadi.online proxy): the Bearer
 * must be a live game:jet player token, and its customer becomes the request's user for the channel callbacks.
 */
class AuthenticateJetBroadcast
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $customer = AuthenticateJetPlayer::playerFor((string) $request->bearerToken());
        $request->setUserResolver(fn () => $customer);

        return $next($request);
    }
}
