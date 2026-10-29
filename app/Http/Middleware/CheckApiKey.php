<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class CheckApiKey
{
    /**
     * Accept any active key. A shop key (scope `shop`) only works on /v1/shop/* routes, so a key handed to
     * a shop's system cannot reach anything else; site and game keys only reach the routes listed for
     * their scope in config/api_scopes.php. Full keys are not fenced. The key is kept on the request as
     * `api_key`.
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

        if (($key->isSiteKey() || $key->isGameKey()) && ! $this->routeAllowed($key, $request)) {
            Log::warning('API key refused outside its scope', [
                'api_key_id' => $key->id,
                'scope' => $key->scope,
                'route' => $this->routeName($request),
            ]);

            return response()->json(['message' => 'This API key cannot use this route', 'code' => 'route_not_allowed'], 403);
        }

        $request->attributes->set('api_key', $key);

        return $next($request);
    }

    /**
     * Site and game keys reach only the routes listed for their scope in config/api_scopes.php.
     */
    private function routeAllowed(ApiKey $key, Request $request): bool
    {
        return in_array($this->routeName($request), config('api_scopes.'.$key->scope, []), true);
    }

    /**
     * "{METHOD} {route uri}" of the matched route. The method is the one the router matched on (so a
     * _method override is checked as the method it became), and HEAD is checked as GET.
     */
    private function routeName(Request $request): string
    {
        $method = $request->method() === 'HEAD' ? 'GET' : $request->method();

        return $method.' '.($request->route()?->uri() ?? $request->path());
    }
}
