<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Services\StkService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class StkLoadController extends Controller
{
    public function __construct(
        private StkService $stkService
    ) {}

    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, $encryptedIdentifier)
    {
        try {
            $request->validate([
                'amount' => 'required',
                'type' => 'required',
            ]);

            $result = $this->stkService->initiateStkLoad(
                $encryptedIdentifier,
                $request->amount,
                $request->type,
                $request->coin_value,
                $request->phone_no,
                $request->referral_code
            );

            return response()->json(['status' => $result['status']], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        } catch (\Exception $e) {
            Log::channel('mpesa')->error('MPESA StkPush Load Response Error', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Could not start the M-Pesa payment, please try again'], 500);
        }
    }
}
