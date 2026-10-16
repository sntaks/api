<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Services\GameWalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GameRefundController extends Controller
{
    public function __construct(
        private GameWalletService $gameWalletService
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'game_wallet_id' => 'required|integer',
        ]);

        try {
            $result = $this->gameWalletService->processFullRefund(
                $request->input('game_wallet_id')
            );

            if (isset($result['error'])) {
                return response()->json(array_filter([
                    'status' => 'error',
                    'error' => $result['error'],
                    'code' => $result['code'] ?? null,
                ]), $result['status']);
            }

            return response()->json(['status' => 'success']);
        } catch (\Exception $e) {
            Log::error('Game Full Refund Error', [
                'message' => $e->getMessage(),
                'request' => $request->all(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'An error occurred during the refund process.',
            ], 500);
        }
    }
}
