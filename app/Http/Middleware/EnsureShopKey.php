<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Models\BetShop;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureShopKey
{
    /**
     * Shop routes need a shop key whose shop is active. The shop always comes from the key, never from
     * the request, so one shop cannot act as another. It is kept on the request as `bet_shop`.
     * Runs after CheckApiKey.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->attributes->get('api_key');

        if (! $key instanceof ApiKey || ! $key->isShopKey()) {
            return response()->json(['message' => 'Shop routes need a shop API key'], 403);
        }

        $shop = $key->betShop;

        if (! $shop || ! $shop->isActive()) {
            return response()->json([
                'code' => 'shop_not_active',
                'message' => 'This shop is '.($shop?->status ?? 'unknown').'. Contact Kadi Kings.',
            ], 403);
        }

        $request->attributes->set('bet_shop', $shop);

        return $next($request);
    }

    /**
     * The shop the current request acts for. Only call on routes behind this middleware.
     */
    public static function shop(Request $request): BetShop
    {
        return $request->attributes->get('bet_shop');
    }
}
