<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Models\BetShop;
use App\Models\BetShopLoad;
use App\Services\BetShopLoadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GMS: every shop's cash loads, and reversing a load made by mistake (shops cannot reverse their own).
 */
class BetShopLoadController extends Controller
{
    public function __construct(private BetShopLoadService $loads) {}

    /**
     * Loads across every shop, or one shop's when the route names it.
     */
    public function index(Request $request, ?string $encryptedIdentifier = null): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:'.BetShopLoad::STATUS_COMPLETED.','.BetShopLoad::STATUS_REVERSED],
            'customer_id' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $shop = null;

        if ($encryptedIdentifier !== null) {
            $shop = BetShop::find($encryptedIdentifier);

            if (! $shop) {
                return response()->json(['success' => false, 'message' => 'Shop not found'], 404);
            }
        }

        return $this->pagedResponse(
            $this->loads->paginate($shop, $filters, (int) ($filters['per_page'] ?? 50)),
            fn (BetShopLoad $load) => $this->loads->present($load, forGms: true)
        );
    }

    public function reverse(Request $request, string $encryptedIdentifier): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);

        $result = $this->loads->reverse((int) $encryptedIdentifier, $validated['reason'], $this->actorFor($request));

        return response()->json(array_filter([
            'success' => $result['success'],
            'code' => $result['code'] ?? null,
            'message' => $result['message'],
            'data' => isset($result['load']) ? $this->loads->present($result['load'], forGms: true) : null,
        ], fn ($value) => $value !== null), $result['status_code']);
    }
}
