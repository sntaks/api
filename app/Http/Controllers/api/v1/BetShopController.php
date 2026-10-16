<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBetShopRequest;
use App\Http\Requests\UpdateBetShopRequest;
use App\Models\ApiKey;
use App\Models\BetShop;
use App\Services\BetShopService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GMS: onboard and run bet shops, and issue the API keys their shop systems use.
 */
class BetShopController extends Controller
{
    public function __construct(private BetShopService $shops) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:'.implode(',', BetShop::STATUSES)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        return $this->pagedResponse(
            $this->shops->paginate($filters, (int) ($filters['per_page'] ?? 50)),
            fn (BetShop $shop) => $this->shops->present($shop)
        );
    }

    public function store(StoreBetShopRequest $request): JsonResponse
    {
        $shop = $this->shops->create($request->validated(), $this->actorFor($request));

        return response()->json(['success' => true, 'message' => 'Shop created', 'data' => $this->shops->present($shop)], 201);
    }

    public function show(string $encryptedIdentifier): JsonResponse
    {
        $shop = BetShop::with('wallet')->find($encryptedIdentifier);

        if (! $shop) {
            return $this->notFound();
        }

        return response()->json(['success' => true, 'data' => $this->shops->present($shop)]);
    }

    public function update(UpdateBetShopRequest $request, string $encryptedIdentifier): JsonResponse
    {
        $shop = BetShop::find($encryptedIdentifier);

        if (! $shop) {
            return $this->notFound();
        }

        return response()->json(['success' => true, 'message' => 'Shop updated', 'data' => $this->shops->present($this->shops->update($shop, $request->validated()))]);
    }

    /**
     * Stop the shop's keys working. The float is kept, and float can still be bought for it.
     */
    public function suspend(Request $request, string $encryptedIdentifier): JsonResponse
    {
        return $this->changeStatus($request, $encryptedIdentifier, BetShop::STATUS_SUSPENDED, true);
    }

    public function activate(Request $request, string $encryptedIdentifier): JsonResponse
    {
        return $this->changeStatus($request, $encryptedIdentifier, BetShop::STATUS_ACTIVE, false);
    }

    /**
     * Close the shop for good (its float must be empty); its keys are revoked.
     */
    public function close(Request $request, string $encryptedIdentifier): JsonResponse
    {
        return $this->changeStatus($request, $encryptedIdentifier, BetShop::STATUS_CLOSED, true);
    }

    public function keys(string $encryptedIdentifier): JsonResponse
    {
        $shop = BetShop::find($encryptedIdentifier);

        if (! $shop) {
            return $this->notFound();
        }

        return response()->json([
            'success' => true,
            'data' => $shop->apiKeys()->latest('id')->get()->map(fn (ApiKey $key) => $this->shops->presentKey($key))->all(),
        ]);
    }

    /**
     * Issue a key for the shop's system. The key is in this response only.
     */
    public function issueKey(Request $request, string $encryptedIdentifier): JsonResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'min:3', 'max:255']]);
        $shop = BetShop::find($encryptedIdentifier);

        if (! $shop) {
            return $this->notFound();
        }

        $result = $this->shops->issueKey($shop, $validated['name']);

        if (! $result['success']) {
            return response()->json(['success' => false, 'code' => $result['code'] ?? null, 'message' => $result['message']], $result['status_code']);
        }

        return response()->json([
            'success' => true,
            'message' => $result['message'],
            'data' => $this->shops->presentKey($result['api_key']) + ['key' => $result['plain_key']],
        ], 201);
    }

    public function revokeKey(Request $request, string $encryptedIdentifier): JsonResponse
    {
        $result = $this->shops->revokeKey((int) $encryptedIdentifier, $this->actorFor($request));

        return response()->json(array_filter([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => isset($result['api_key']) ? $this->shops->presentKey($result['api_key']) : null,
        ], fn ($value) => $value !== null), $result['status_code']);
    }

    private function changeStatus(Request $request, string $encryptedIdentifier, string $status, bool $needsReason): JsonResponse
    {
        $validated = $request->validate(['reason' => [$needsReason ? 'required' : 'nullable', 'string', 'min:3', 'max:255']]);
        $shop = BetShop::find($encryptedIdentifier);

        if (! $shop) {
            return $this->notFound();
        }

        $result = $this->shops->changeStatus($shop, $status, $validated['reason'] ?? null, $this->actorFor($request));

        return response()->json(array_filter([
            'success' => $result['success'],
            'code' => $result['code'] ?? null,
            'message' => $result['message'],
            'data' => isset($result['shop']) ? $this->shops->present($result['shop']) : null,
        ], fn ($value) => $value !== null), $result['status_code']);
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'Shop not found'], 404);
    }
}
