<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\PagedListRequest;
use App\Http\Requests\StoreGameTransactionRequest;
use App\Http\Resources\GameTransactionResource;
use App\Services\GameWalletService;
use Illuminate\Http\JsonResponse;

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
            $result = $this->gameWalletService->createGameTransaction($request->validated());

            if ($result === false) {
                return response()->json(['error' => 'Insufficient balance or Wallet not found.'], 400);
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
        $gameTransaction = $this->gameWalletService->updateGameTransaction($encryptedIdentifier, $request->validated());
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
