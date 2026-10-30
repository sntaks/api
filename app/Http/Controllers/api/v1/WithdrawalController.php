<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Services\WithdrawalRules;
use App\Services\WithdrawalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class WithdrawalController extends Controller
{
    public function __construct(
        private WithdrawalService $withdrawalService
    ) {}

    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, $identifier): JsonResponse
    {
        try {
            $validatedData = $request->validate(['amount' => WithdrawalRules::amountRules()]);

            $result = $this->withdrawalService->initiateWithdrawal($identifier, $validatedData['amount'], $this->actorFor($request));

            $statusCode = $result['status_code'] ?? 500;

            // A withdrawal of approval_threshold or more is held: the money is out of the wallet, the payout waits for GMS.
            if ($result['held'] ?? false) {
                return response()->json([
                    'status' => 'awaiting_approval',
                    'withdrawal_id' => $result['withdrawal_id'],
                    'ledger_entry_id' => $result['ledger_entry_id'],
                ], 202);
            }

            return response()->json([
                'status' => $result['success'] ? 'success' : $result['message'],
                'ledger_entry_id' => $result['ledger_entry_id'] ?? null,
            ] + array_filter([
                'code' => $result['code'] ?? null,
                'retry_after' => $result['retry_after'] ?? null,
                'locked_amount' => $result['locked_amount'] ?? null,
                'available' => $result['available'] ?? null,
            ], fn ($value) => $value !== null), $statusCode);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::channel('mpesa')->error('Withdraw Request Error: '.$e->getMessage(), [
                'identifier' => $identifier,
                'request_data' => $request->all(),
            ]);

            return response()->json([
                'status' => $e->getMessage(),
            ], 500);
        }
    }
}
