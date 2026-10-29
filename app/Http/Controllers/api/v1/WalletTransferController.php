<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WalletTransferController extends Controller
{
    public function __construct(
        private WalletService $walletService
    ) {}

    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, $encryptedIdentifier)
    {
        // Outside the try: the catch-all below would turn a validation failure into a 500.
        $validated = $request->validate([
            'amount' => 'required|numeric|gt:0|decimal:0,2',
            'wallet_id' => 'nullable|integer',
        ]);

        try {
            $result = $this->walletService->transfer(
                $encryptedIdentifier,
                $validated['amount'],
                $validated['wallet_id'] ?? null
            );

            if (! $result['success']) {
                $statusCode = $result['status_code'] ?? 500;

                return response()->json(['status' => 'error', 'message' => $result['message']] + array_filter([
                    'code' => $result['code'] ?? null,
                    'locked_amount' => $result['locked_amount'] ?? null,
                    'available' => $result['available'] ?? null,
                ], fn ($value) => $value !== null), $statusCode);
            }

            return response()->json(['status' => 'success'], 200);
        } catch (\Exception $e) {
            Log::error('Wallet Transfer Error', [
                'error' => $e->getMessage(),
                'encryptedIdentifier' => $encryptedIdentifier,
                'request' => $request->all(),
            ]);

            return response()->json(['status' => 'error'], 500);
        }
    }
}
