<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Services\StkService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class StkDepositController extends Controller
{
    public function __construct(
        private StkService $stkService
    ) {}

    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, $identifier)
    {
        try {
            $request->validate([
                'amount' => 'required|numeric|min:1',
            ]);

            $result = $this->stkService->initiateStkDeposit($identifier, $request->amount);

            if (isset($result['refusal'])) {
                return $result['refusal']->toResponse();
            }

            return response()->json(['status' => $result['status']], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        } catch (\Exception $e) {
            Log::error('MPESA StkPush Response Error', ['error' => $e->getMessage()]);

            return response()->json(['status' => $e->getMessage()], 500);
        }
    }
}
