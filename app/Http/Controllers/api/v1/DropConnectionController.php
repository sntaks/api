<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Services\GameWalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DropConnectionController extends Controller
{
    public function __construct(
        private GameWalletService $gameWalletService
    ) {}

    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, $encryptedIdentifier): JsonResponse
    {
        try {
            $result = $this->gameWalletService->processDropConnection(
                $encryptedIdentifier,
                $request->players,
                $request->active,
                $request->dropped,
                $request->input('game', 0) == 1
            );

            if (isset($result['error'])) {
                return response()->json(array_filter(['status' => $result['error'], 'code' => $result['code'] ?? null]), $result['status']);
            }

            return response()->json([
                'status' => 'success',
                'payouts' => $result['payouts'],
            ], 201);
        } catch (\Exception $e) {
            Log::error('Drop Connection Error: ', ['error' => $e->getMessage()]);

            return response()->json(['status' => 'Error processing request: '.$e->getMessage()], 500);
        }
    }
}
