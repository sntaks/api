<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\PagedListRequest;
use App\Http\Requests\StoreGameTransactionRequest;
use App\Http\Resources\GameTransactionResource;
use App\Services\GameWalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;

class GameTransactionController extends Controller
{
    public function __construct(
        private GameWalletService $gameWalletService
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(PagedListRequest $request)
    {
        return GameTransactionResource::collection($this->gameWalletService->listGameTransactions($request->perPage()));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreGameTransactionRequest $request): JsonResponse
    {
        try {
            $result = $this->gameWalletService->createGameTransaction($request->stakeData());

            if ($result === false) {
                return response()->json(['error' => 'Insufficient balance or Wallet not found.'], 400);
            }

            if (is_array($result)) {
                return response()->json(['error' => $result['error'], 'code' => $result['code']], $result['status']);
            }

            return response()->json(['status' => 'success'], 201);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show($encryptedIdentifier)
    {
        $gameTransaction = $this->gameWalletService->getGameTransaction($encryptedIdentifier);
        if (! $gameTransaction) {
            return response()->json(['message' => 'Game Transaction not found'], 404);
        }

        return GameTransactionResource::make($gameTransaction);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(StoreGameTransactionRequest $request, $encryptedIdentifier): JsonResponse
    {
        // A stake's shop is set when it is made and never changed.
        $gameTransaction = $this->gameWalletService->updateGameTransaction($encryptedIdentifier, Arr::except($request->validated(), ['bet_shop_no']));
        if (! $gameTransaction) {
            return response()->json(['error' => 'Game Transaction not found'], 404);
        }

        return response()->json(['status' => 'success'], 201);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($encryptedIdentifier): JsonResponse
    {
        $deleted = $this->gameWalletService->deleteGameTransaction($encryptedIdentifier);
        if (! $deleted) {
            return response()->json(['message' => 'Game Transaction not found'], 404);
        }

        return response()->json(['message' => 'Game Transaction deleted successfully'], 200);
    }
}
