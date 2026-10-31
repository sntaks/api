<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminVoidWagerRequest;
use App\Http\Requests\InstantWagerRequest;
use App\Http\Requests\LookupWagerRequest;
use App\Http\Requests\PlaceWagerRequest;
use App\Http\Requests\ReverseWagerRequest;
use App\Http\Requests\SettleWagerRequest;
use App\Http\Resources\WagerAdminResource;
use App\Http\Resources\WagerAdminSettlementResource;
use App\Http\Resources\WagerResource;
use App\Http\Resources\WagerSettlementResource;
use App\Models\Wager;
use App\Services\WagerResult;
use App\Services\WagerService;
use Illuminate\Http\JsonResponse;

/**
 * The wager API for house-banked games (game key; placing also needs the player token).
 */
class WagerController extends Controller
{
    public function __construct(private WagerService $wagers) {}

    public function store(PlaceWagerRequest $request): JsonResponse
    {
        $result = $this->wagers->place($request->attributes->get('api_key'), $request->attributes->get('player'), $request->validated());

        return $this->respond($result);
    }

    public function settle(SettleWagerRequest $request, string $reference): JsonResponse
    {
        $data = $request->validated();

        return $this->respond($this->wagers->settle($request->attributes->get('api_key'), $data['product'], $reference, $data));
    }

    /**
     * A bet and its result in one call (a slot spin, a scratch card): game key and player token.
     */
    public function instant(InstantWagerRequest $request): JsonResponse
    {
        return $this->respond($this->wagers->placeAndSettle($request->attributes->get('api_key'), $request->attributes->get('player'), $request->validated()));
    }

    public function reverse(ReverseWagerRequest $request, string $reference): JsonResponse
    {
        $data = $request->validated();

        return $this->respond($this->wagers->reverse($request->attributes->get('api_key'), $data['product'], $reference, $data));
    }

    /**
     * Reconcile after a timeout: the game reads its own wager and every settlement version (tombstones too).
     */
    public function show(LookupWagerRequest $request, string $reference): JsonResponse
    {
        $data = $request->validated();
        $product = $this->wagers->allowedProduct($request->attributes->get('api_key'), $data['product']);

        if (! $product) {
            return $this->respond(WagerResult::refused('product_not_allowed', 'This key cannot use that product', 403));
        }

        $wager = Wager::where('product_id', $product->id)->where('reference', $reference)->with(['product', 'round'])->first();

        if (! $wager) {
            return $this->respond(WagerResult::refused('wager_not_found', 'No wager has that reference', 404));
        }

        return response()->json(['success' => true, 'data' => [
            'wager' => WagerResource::make($wager),
            'settlements' => WagerSettlementResource::collection($wager->settlements()->orderBy('version')->orderBy('id')->get()),
        ]]);
    }

    /**
     * GMS: void a stuck open wager by its id. The admin version is a conflict for the game's own settle of it.
     */
    public function adminVoid(AdminVoidWagerRequest $request, string $id): JsonResponse
    {
        $wager = ctype_digit($id) ? Wager::with(['product.game', 'round'])->find((int) $id) : null;

        if (! $wager) {
            return $this->respond(WagerResult::refused('wager_not_found', 'No wager has that id', 404));
        }

        $data = $request->validated();
        $result = $this->wagers->adminVoid($wager, $data['reason'], $data['admin_user']);

        if ($result->isRefused()) {
            return $this->respond($result);
        }

        return response()->json(['success' => true, 'data' => [
            'wager' => WagerAdminResource::make($result->wager->loadMissing('product.game')),
            'settlement' => WagerAdminSettlementResource::make($result->settlement),
            'replayed' => false,
        ]]);
    }

    private function respond(WagerResult $result): JsonResponse
    {
        if ($result->isRefused()) {
            $refusal = $result->refusal;

            return response()->json([
                'success' => false,
                'message' => $refusal['message'],
                'code' => $refusal['code'],
                'retryable' => $refusal['status'] >= 500 || $refusal['status'] === 429,
            ] + $refusal['extra'], $refusal['status']);
        }

        if ($result->settlement && $result->balance !== null) {
            return response()->json(['success' => true, 'data' => [
                'wager' => WagerResource::make($result->wager),
                'settlement' => WagerSettlementResource::make($result->settlement),
                'balance' => $result->balance,
                'replayed' => $result->replayed,
            ]], $result->replayed ? 200 : 201);
        }

        if ($result->settlement) {
            return response()->json(['success' => true, 'data' => [
                'wager' => WagerResource::make($result->wager),
                'settlement' => WagerSettlementResource::make($result->settlement),
                'replayed' => $result->replayed,
            ]], $result->created ? 201 : 200);
        }

        return response()->json(['success' => true, 'data' => [
            'wager' => WagerResource::make($result->wager),
            'balance' => $result->balance,
            'replayed' => $result->replayed,
        ]], $result->replayed ? 200 : 201);
    }
}
