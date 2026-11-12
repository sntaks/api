<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Services\CoinService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CoinTransferController extends Controller
{
    public function __construct(
        private CoinService $coinService
    ) {}

    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, $encryptedIdentifier)
    {
        try {
            $validated = $request->validate([
                'coins' => 'required|numeric',
                'coin_wallet_id' => 'nullable|integer',
            ]);

            $result = $this->coinService->transferCoins(
                $encryptedIdentifier,
                $validated['coins'],
                $validated['coin_wallet_id'] ?? null
            );

            $statusCode = $result['status_code'] ?? 500;

            return response()->json(['message' => $result['message']], $statusCode);
        } catch (\Exception $e) {
            Log::error('Coin Transfer Error', [
                'error' => $e->getMessage(),
                'encryptedIdentifier' => $encryptedIdentifier,
                'request' => $request->all(),
            ]);

            return response()->json(['message' => 'Coin Transfer Failed'], 500);
        }
    }
}
