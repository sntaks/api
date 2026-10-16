<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireIdempotencyKey
{
    /**
     * Refuse a request without an Idempotency-Key header. Without one, EnsureIdempotency keys the request
     * on its body, so two identical cash loads (same player, same amount) within 24 hours would be treated
     * as one and the second never made, while the shop has taken the cash. Only used on routes that were
     * built requiring it, so no existing client is affected.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (blank($request->header('Idempotency-Key'))) {
            return response()->json([
                'code' => 'idempotency_key_required',
                'message' => 'Send an Idempotency-Key header: a new one for each load, and the same one when retrying it.',
            ], 400);
        }

        return $next($request);
    }
}
