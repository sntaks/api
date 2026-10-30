<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\PlaceWagerRequest;
use App\Http\Requests\ReverseWagerRequest;
use App\Http\Requests\SettleWagerRequest;
use App\Http\Resources\WagerResource;
use App\Http\Resources\WagerSettlementResource;
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

    public function reverse(ReverseWagerRequest $request, string $reference): JsonResponse
    {
        $data = $request->validated();

        return $this->respond($this->wagers->reverse($request->attributes->get('api_key'), $data['product'], $reference, $data));
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
