<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListGameResultsRequest;
use App\Http\Requests\PagedListRequest;
use App\Http\Requests\StoreGameWalletRequest;
use App\Http\Requests\UpdateGameWalletRequest;
use App\Http\Resources\GameWalletResource;
use App\Services\GameWalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GameWalletController extends Controller
{
    public function __construct(
        private GameWalletService $gameWalletService
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(PagedListRequest $request)
    {
        return GameWalletResource::collection($this->gameWalletService->listGameWallets($request->perPage()));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreGameWalletRequest $request): JsonResponse
    {
        $gameWallet = $this->gameWalletService->createGameWallet($request->validated());

        return response()->json(['status' => 'success', 'game_wallet_id' => $gameWallet->id, 'game_type' => $gameWallet->game_type], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show($encryptedIdentifier)
    {
        $gameWallet = $this->gameWalletService->getGameWallet($encryptedIdentifier);
        if (! $gameWallet) {
            return response()->json(['message' => 'Game Wallet not found'], 404);
        }

        return GameWalletResource::make($gameWallet);
    }

    public function game_income(Request $request): JsonResponse
    {
        $incomes = $this->gameWalletService->getGameIncome(
            $request->input('start_date'),
            $request->input('end_date')
        );

        return response()->json(['status' => 'Success', 'data' => $incomes]);
    }

    /**
     * Decided games with each player's result. Paged (newest first) only when per_page is sent.
     */
    public function game_results(ListGameResultsRequest $request): JsonResponse
    {
        if (! $request->filled('per_page')) {
            return response()->json(['status' => 'Success', 'data' => $this->gameWalletService->getGameResults()]);
        }

        $results = $this->gameWalletService->paginateGameResults(
            $request->integer('per_page'),
            $request->integer('page', 1)
        );

        return response()->json(['status' => 'Success', 'data' => $results['items'], 'pagination' => $results['pagination']]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateGameWalletRequest $request, $encryptedIdentifier): JsonResponse
    {
        $gameWallet = $this->gameWalletService->updateGameWallet($encryptedIdentifier, $request->validated());
        if (! $gameWallet) {
            return response()->json(['message' => 'Game Wallet not found'], 404);
        }

        return response()->json(['status' => 'success'], 201);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($encryptedIdentifier): JsonResponse
    {
        $deleted = $this->gameWalletService->deleteGameWallet($encryptedIdentifier);
        if (! $deleted) {
            return response()->json(['message' => 'Game Wallet not found'], 404);
        }

        return response()->json(['status' => 'Success'], 200);
    }
}
