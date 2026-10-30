<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Services\CustomerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class CustomerController extends Controller
{
    public function __construct(private CustomerService $customerService) {}

    public function index()
    {
        return CustomerResource::collection(
            $this->customerService->listActiveCustomers()
        );
    }

    public function search(Request $request)
    {
        $query = $request->query('q');

        if (! $query) {
            return response()->json(['message' => 'Search query is required'], 422);
        }

        return CustomerResource::collection(
            $this->customerService->searchCustomers($query)
        );
    }

    public function customersReferrals(Request $request)
    {
        return CustomerResource::collection(
            $this->customerService->getCustomersByReferralCodes($request->referral_code)
        );
    }

    public function store(StoreCustomerRequest $request)
    {
        try {
            $customer = $this->customerService->createCustomer($request->validated());

            return response()->json([
                'status' => 'Success',
                'customer_id' => $customer->id,
                'promo_code_applied' => $customer->promo_code_id !== null,
            ], 201);
        } catch (\Exception $e) {
            Log::error('Create Customer Error: ', ['error' => $e->getMessage()]);

            return response()->json(['status' => 'Error creating Customer'], 500);
        }
    }

    public function show($encryptedIdentifier)
    {
        $customer = $this->customerService->getCustomer($encryptedIdentifier);

        if (! $customer) {
            return response()->json(['message' => 'Customer not found'], 404);
        }

        return CustomerResource::make($customer);
    }

    public function customer_transactions(Request $request, $encryptedIdentifier): JsonResponse
    {
        try {
            $request->validate(['payment_type' => 'required']);

            $result = $this->customerService->getCustomerTransactions($encryptedIdentifier, $request->payment_type);

            if (! $result['customer']) {
                return response()->json(['message' => 'Customer not found'], 404);
            }

            if (isset($result['wallet']) && ! $result['wallet']) {
                return response()->json(['message' => 'Customer wallet not found'], 404);
            }

            if (isset($result['invalid_type'])) {
                return response()->json(['message' => 'Invalid payment type'], 400);
            }

            return response()->json(['total' => $result['total'], 'transactions' => $result['transactions']], 200);
        } catch (\Exception $e) {
            Log::error('Customer Get Transactions Error: ', ['error' => $e->getMessage()]);

            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function customer_played($encryptedIdentifier): JsonResponse
    {
        try {
            $result = $this->customerService->getCustomerPlayedGames($encryptedIdentifier);

            if (! $result['customer']) {
                return response()->json(['message' => 'Customer not found'], 404);
            }

            return response()->json([
                'single_games' => $result['single_games'],
                'tournament_games' => $result['tournament_games'],
                'jackpot_games' => $result['jackpot_games'],
            ]);
        } catch (\Exception $e) {
            Log::error('Customer Get Played Error: ', ['error' => $e->getMessage()]);

            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function customer_recent_played($encryptedIdentifier): JsonResponse
    {
        try {
            $result = $this->customerService->getCustomerRecentPlayedGames($encryptedIdentifier);

            if (! $result['customer']) {
                return response()->json(['message' => 'Customer not found'], 404);
            }

            return response()->json([
                'single_games' => $result['single_games'],
                'tournament_games' => $result['tournament_games'],
                'jackpot_games' => $result['jackpot_games'],
            ]);
        } catch (\Exception $e) {
            Log::error('Customer Get Recent Played Error: ', ['error' => $e->getMessage()]);

            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function customer_leaderboard(Request $request): JsonResponse
    {
        try {
            $request->merge([
                'start_date' => $request->start_date ?? now()->subMonths(3)->startOfMonth()->format('Y-m-d'),
                'end_date' => $request->end_date ?? now()->endOfMonth()->format('Y-m-d'),
            ]);
            $request->validate([
                'start_date' => 'required|date',
                'end_date' => 'required|date',
            ]);

            $data = $this->customerService->getCustomerLeaderboard(
                $request->start_date,
                $request->end_date,
            );

            return response()->json($data);
        } catch (\Exception $e) {
            Log::error('Customer Get Leaderboard Error: ', ['error' => $e->getMessage()]);

            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function combined_leaderboard(Request $request): JsonResponse
    {
        try {
            $data = $this->customerService->getCombinedLeaderboard();

            return response()->json($data);
        } catch (\Exception $e) {
            Log::error('Customer Get Combined Leaderboard Error: ', ['error' => $e->getMessage()]);

            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function customer_purchases($encryptedIdentifier)
    {
        try {
            $purchases = $this->customerService->getCustomerPurchases($encryptedIdentifier);

            return response()->json(['data' => $purchases], 200);
        } catch (\Exception $e) {
            Log::error('Customer Get Purchases Error: ', ['error' => $e->getMessage()]);

            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function update(UpdateCustomerRequest $request, $encryptedIdentifier)
    {
        $customer = $this->customerService->updateCustomer($encryptedIdentifier, $request->validated(), $this->actorFor($request));

        if (! $customer) {
            return response()->json(['message' => 'Customer not found'], 404);
        }

        return CustomerResource::make($customer);
    }

    public function update_wallet(Request $request, $encryptedIdentifier): JsonResponse
    {
        $request->validate(['amount' => 'required|numeric', 'reason' => 'nullable|string|max:255']);

        $result = $this->customerService->updateCustomerWallet($encryptedIdentifier, (float) $request->amount, $request->input('reason'), $this->actorFor($request));

        if (! $result['success']) {
            return response()->json(['message' => $result['message']], $result['status_code']);
        }

        return response()->json(['status' => 'success'], 201);
    }

    public function destroy($encryptedIdentifier): JsonResponse
    {
        $result = $this->customerService->deleteCustomer($encryptedIdentifier);

        return response()->json(Arr::except($result, ['success', 'status_code']), $result['status_code']);
    }
}
