<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DepositResolutionResource;
use App\Models\BetShop;
use App\Models\BetShopFloatPurchase;
use App\Services\BetShopFloatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GMS: credit a shop owner's paybill payment (bill ref SHOP-<shop_no>) to the shop's float, after
 * checking the M-Pesa statement, or void that.
 */
class BetShopFloatController extends Controller
{
    public function __construct(private BetShopFloatService $floats) {}

    public function store(Request $request, string $encryptedIdentifier): JsonResponse
    {
        $validated = $request->validate([
            'shop_no' => ['required', 'string', 'max:10'],
            'note' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $shop = BetShop::where('shop_no', strtoupper(trim($validated['shop_no'])))->first();

        if (! $shop) {
            return response()->json(['success' => false, 'message' => 'Shop not found'], 404);
        }

        return $this->respond($this->floats->credit((int) $encryptedIdentifier, $shop, $validated['note'], $this->actorFor($request)));
    }

    public function void(Request $request, string $encryptedIdentifier): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        return $this->respond($this->floats->void((int) $encryptedIdentifier, $validated['reason'], $this->actorFor($request)));
    }

    public function index(Request $request, string $encryptedIdentifier): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);
        $shop = BetShop::find($encryptedIdentifier);

        if (! $shop) {
            return response()->json(['success' => false, 'message' => 'Shop not found'], 404);
        }

        return $this->pagedResponse(
            $this->floats->paginate($shop, (int) ($validated['per_page'] ?? 50)),
            fn (BetShopFloatPurchase $purchase) => $this->floats->present($purchase)
        );
    }

    /**
     * A refused request carries `code` (`already_resolved`, `insufficient_float`, `shop_closed`).
     *
     * @param  array<string, mixed>  $result
     */
    private function respond(array $result): JsonResponse
    {
        $deposit = $result['deposit'] ?? null;
        $purchase = $result['float_purchase'] ?? null;

        return response()->json(array_filter([
            'success' => $result['success'],
            'code' => $result['code'] ?? null,
            'message' => $result['message'],
            'data' => $deposit || $purchase ? array_filter([
                'float_purchase' => $purchase ? $this->floats->present($purchase) : null,
                'deposit' => $deposit ? [
                    'id' => $deposit->id,
                    'trans_id' => $deposit->trans_id,
                    'amount' => (float) $deposit->trans_amount,
                    'bill_ref_no' => $deposit->bill_ref_no,
                    'status' => (int) $deposit->status,
                    'resolution' => $deposit->resolution ? DepositResolutionResource::make($deposit->resolution) : null,
                ] : null,
            ], fn ($value) => $value !== null) : null,
        ], fn ($value) => $value !== null), $result['status_code']);
    }
}
