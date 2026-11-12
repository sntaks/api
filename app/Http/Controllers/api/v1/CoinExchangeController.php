<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Services\CoinService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CoinExchangeController extends Controller
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
                'coins' => 'nullable|numeric',
            ]);

            $result = $this->coinService->exchangeCoins($encryptedIdentifier, $validated['coins'] ?? null);

            $statusCode = $result['status_code'] ?? 500;

            return response()->json(['message' => $result['message']], $statusCode);
        } catch (\Exception $e) {
            Log::error('Coin Exchange Error', [
                'error' => $e->getMessage(),
                'encryptedIdentifier' => $encryptedIdentifier,
                'request' => $request->all(),
            ]);

            return response()->json(['message' => 'Coin Exchange Failed'], 500);
        }
    }
}
