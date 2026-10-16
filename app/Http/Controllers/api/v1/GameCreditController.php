<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreGameCreditRequest;
use App\Services\GameWalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Pays a remaining player their share of a game voided because another player dropped out
 * (called by the game server once per remaining player).
 */
class GameCreditController extends Controller
{
    public function __construct(
        private GameWalletService $gameWalletService
    ) {}

    public function __invoke(StoreGameCreditRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $result = $this->gameWalletService->processDroppedGameShare(
                (int) $validated['game_wallet_id'],
                (int) $validated['customer_id'],
                (float) $validated['amount']
            );

            if (isset($result['error'])) {
                return response()->json([
                    'status' => 'error',
                    'error' => $result['error'],
                ], $result['status']);
            }

            return response()->json(['status' => 'success'], 201);
        } catch (\Exception $e) {
            Log::error('Game Credit Share Error', [
                'message' => $e->getMessage(),
                'request' => $validated,
            ]);

            return response()->json([
                'status' => 'error',
                'error' => 'An error occurred while crediting the share.',
            ], 500);
        }
    }
}
