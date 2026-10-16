<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Services\GameWalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GameWalletWithdrawController extends Controller
{
    public function __construct(
        private GameWalletService $gameWalletService
    ) {}

    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, $encryptedIdentifier): JsonResponse
    {
        $request->validate([
            'customer_id' => 'required|string',
        ]);

        try {
            $result = $this->gameWalletService->processGameWithdrawal(
                $encryptedIdentifier,
                $request->customer_id
            );

            if (isset($result['error'])) {
                return response()->json(array_filter(['error' => $result['error'], 'code' => $result['code'] ?? null]), $result['status']);
            }

            return response()->json(['status' => 'success'], 201);
        } catch (\Exception $e) {
            Log::error('Game Wallet Withdrawal Error', [
                'message' => $e->getMessage(),
                'request' => $request->all(),
                'game_wallet_id' => $encryptedIdentifier,
            ]);

            return response()->json([
                'error' => 'An error occurred during the withdrawal process.',
            ], 500);
        }
    }
}
