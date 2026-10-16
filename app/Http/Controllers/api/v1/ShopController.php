<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureShopKey;
use App\Http\Requests\StoreShopLoadRequest;
use App\Models\BetShopLoad;
use App\Services\BetShopLoadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The shop system (shop API key). Every action is for the shop the key belongs to.
 */
class ShopController extends Controller
{
    public function __construct(private BetShopLoadService $loads) {}

    public function me(Request $request): JsonResponse
    {
        $shop = EnsureShopKey::shop($request)->load('wallet');

        return response()->json([
            'success' => true,
            'data' => [
                'shop_no' => $shop->shop_no,
                'name' => $shop->name,
                'location' => $shop->location,
                'status' => $shop->status,
                'float_balance' => round((float) ($shop->wallet?->balance ?? 0), 2),
                'float_bill_ref' => config('bet_shops.bill_ref_prefix').$shop->shop_no,
                'today' => $this->loads->todayFor($shop),
                'load_limits' => [
                    'min_amount' => (int) config('bet_shops.loads.min_amount'),
                    'max_amount' => (int) config('bet_shops.loads.max_amount'),
                    'daily_limit_per_player' => (int) config('bet_shops.loads.daily_limit_per_player'),
                ],
            ],
        ]);
    }

    /**
     * Check a player number before loading: the masked name lets the owner confirm it with the player.
     */
    public function player(string $playerNo): JsonResponse
    {
        $result = $this->loads->lookup($playerNo);

        return response()->json(array_filter([
            'success' => $result['success'],
            'code' => $result['code'] ?? null,
            'message' => $result['message'],
            'data' => $result['player'] ?? null,
        ], fn ($value) => $value !== null), $result['status_code']);
    }

    public function storeLoad(StoreShopLoadRequest $request): JsonResponse
    {
        $result = $this->loads->load(
            EnsureShopKey::shop($request),
            $request->validated('player_no'),
            (int) $request->validated('amount'),
            (string) $request->header('Idempotency-Key'),
            $this->actorFor($request)
        );

        return response()->json(array_filter([
            'success' => $result['success'],
            'code' => $result['code'] ?? null,
            'message' => $result['message'],
            'data' => isset($result['load']) ? $this->loads->present($result['load']) + ['float_balance' => $result['float_balance']] : null,
            'float_balance' => ! isset($result['load']) && isset($result['float_balance']) ? $result['float_balance'] : null,
        ], fn ($value) => $value !== null), $result['status_code']);
    }

    public function loads(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        return $this->pagedResponse(
            $this->loads->paginate(EnsureShopKey::shop($request), $filters, (int) ($filters['per_page'] ?? 50)),
            fn (BetShopLoad $load) => $this->loads->present($load)
        );
    }
}
