<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckApiKey
{
    /**
     * Accept any active key. A shop key (scope `shop`) only works on /v1/shop/* routes, so a key handed to
     * a shop's system cannot reach anything else. The key is kept on the request as `api_key`.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->header('X-API-KEY');
        $key = $apiKey ? ApiKey::where('key', $apiKey)->where('is_active', true)->first() : null;

        if (! $key) {
            return response()->json(['message' => 'Invalid or missing API key'], 401);
        }

        if ($key->isShopKey() && ! $request->is('api/v1/shop', 'api/v1/shop/*')) {
            return response()->json(['message' => 'A shop API key can only be used on shop routes'], 403);
        }

        $request->attributes->set('api_key', $key);

        return $next($request);
    }
}
