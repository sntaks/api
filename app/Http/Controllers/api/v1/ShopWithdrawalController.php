<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Models\BetShop;
use App\Models\ShopWithdrawal;
use App\Services\CustomerService;
use App\Services\ShopWithdrawalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cash withdrawals at bet shops, for the player (the client, acting for a logged-in customer) and GMS.
 * The shop's side is in ShopController.
 */
class ShopWithdrawalController extends Controller
{
    public function __construct(
        private ShopWithdrawalService $withdrawals,
        private CustomerService $customers,
    ) {}

    /**
     * The player asks for cash at a shop. The pickup code is in this response only.
     */
    public function store(Request $request, string $encryptedIdentifier): JsonResponse
    {
        $validated = $request->validate([
            'shop_no' => ['required', 'string', 'max:10'],
            'amount' => ['required', 'integer', 'min:'.config('bet_shops.withdrawals.min_amount'), 'max:'.config('bet_shops.withdrawals.max_amount')],
        ]);

        $customer = $this->customers->getCustomer($encryptedIdentifier);

        if (! $customer) {
            return response()->json(['success' => false, 'message' => 'Customer not found'], 404);
        }

        $result = $this->withdrawals->request($customer, $validated['shop_no'], (int) $validated['amount'], $this->actorFor($request));

        if (! $result['success']) {
            return response()->json(array_diff_key($result, ['status_code' => true]), $result['status_code']);
        }

        return response()->json([
            'success' => true,
            'message' => $result['message'],
            'data' => $this->withdrawals->present($result['withdrawal'], 'customer') + ['pickup_code' => $result['pickup_code']],
        ], 201);
    }

    public function index(Request $request, string $encryptedIdentifier): JsonResponse
    {
        $filters = $this->listFilters($request);
        $customer = $this->customers->getCustomer($encryptedIdentifier);

        if (! $customer) {
            return response()->json(['success' => false, 'message' => 'Customer not found'], 404);
        }

        return $this->pagedResponse(
            $this->withdrawals->paginate(null, $customer, ['customer_id' => null] + $filters, (int) ($filters['per_page'] ?? 50)),
            fn (ShopWithdrawal $withdrawal) => $this->withdrawals->present($withdrawal, 'customer')
        );
    }

    public function cancel(Request $request, string $encryptedIdentifier, string $withdrawalId): JsonResponse
    {
        $customer = $this->customers->getCustomer($encryptedIdentifier);

        if (! $customer) {
            return response()->json(['success' => false, 'message' => 'Customer not found'], 404);
        }

        return $this->respond($this->withdrawals->cancel($customer, $withdrawalId, $this->actorFor($request)), 'customer');
    }

    /**
     * GMS: every shop's withdrawals, or one shop's when the route names it.
     */
    public function gmsIndex(Request $request, ?string $encryptedIdentifier = null): JsonResponse
    {
        $filters = $this->listFilters($request);
        $shop = null;

        if ($encryptedIdentifier !== null) {
            $shop = BetShop::find($encryptedIdentifier);

            if (! $shop) {
                return response()->json(['success' => false, 'message' => 'Shop not found'], 404);
            }
        }

        return $this->pagedResponse(
            $this->withdrawals->paginate($shop, null, $filters, (int) ($filters['per_page'] ?? 50)),
            fn (ShopWithdrawal $withdrawal) => $this->withdrawals->present($withdrawal, 'gms')
        );
    }

    /**
     * @return array{status?: string, customer_id?: int, from?: string, to?: string, per_page?: int, page?: int}
     */
    private function listFilters(Request $request): array
    {
        return $request->validate([
            'status' => ['nullable', 'in:'.implode(',', ShopWithdrawal::STATUSES)],
            'customer_id' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function respond(array $result, string $audience): JsonResponse
    {
        return response()->json(array_filter([
            'success' => $result['success'],
            'code' => $result['code'] ?? null,
            'message' => $result['message'],
            'data' => isset($result['withdrawal']) ? $this->withdrawals->present($result['withdrawal'], $audience) : null,
        ], fn ($value) => $value !== null), $result['status_code']);
    }
}
