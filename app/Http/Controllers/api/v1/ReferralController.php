<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListReferralsRequest;
use App\Http\Requests\SaveReferralCodeRequest;
use App\Http\Resources\ReferralBonusResource;
use App\Http\Resources\ReferralCodeResource;
use App\Http\Resources\ReferralResource;
use App\Models\Customer;
use App\Models\Referral;
use App\Models\ReferralBonus;
use App\Services\CustomerVerificationService;
use App\Services\ReferralService;
use App\Services\SignupBonusService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReferralController extends Controller
{
    public function __construct(private ReferralService $referrals) {}

    /**
     * Every referral, newest first. Filter by status, referrer, referred customer and signup date.
     */
    public function index(ListReferralsRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

        $referrals = $this->filtered(Referral::query(), $filters)
            ->when(isset($filters['referrer_id']), fn (Builder $query) => $query->where('referrer_id', $filters['referrer_id']))
            ->when(isset($filters['referred_id']), fn (Builder $query) => $query->where('referred_id', $filters['referred_id']))
            ->with(['referred:id,name,phone_no', 'bonuses'])
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 50))
            ->withQueryString();

        return ReferralResource::collection($referrals);
    }

    /**
     * Who owns a code, for showing "Invited by ..." on the signup screen.
     */
    public function lookup(Request $request): JsonResponse
    {
        $request->validate(['code' => 'required|string|max:20']);

        $referralCode = $this->referrals->findCode((string) $request->string('code'));

        if (! $referralCode) {
            return response()->json(['success' => false, 'message' => 'Referral code not found'], 404);
        }

        return response()->json(['success' => true, 'data' => [
            'code' => $referralCode->code,
            'customer_id' => $referralCode->customer_id,
            'name' => $referralCode->customer?->name,
        ]]);
    }

    public function programStats(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->referrals->programStats()]);
    }

    public function showCode(string $encryptedIdentifier): JsonResponse
    {
        $customer = $this->customer($encryptedIdentifier);

        if (! $customer) {
            return $this->customerNotFound();
        }

        if (! $customer->referralCode) {
            return response()->json(['success' => false, 'message' => 'Customer has no referral code'], 404);
        }

        return response()->json(['success' => true, 'data' => ReferralCodeResource::make($customer->referralCode)]);
    }

    /**
     * Set or change the customer's own code, link and QR code. Earlier referrals stay with the customer.
     */
    public function saveCode(SaveReferralCodeRequest $request, string $encryptedIdentifier): JsonResponse
    {
        $customer = $this->customer($encryptedIdentifier);

        if (! $customer) {
            return $this->customerNotFound();
        }

        $owner = $this->referrals->findCode($request->validated('code'));

        if ($owner && $owner->customer_id !== $customer->id) {
            return $this->codeTaken();
        }

        try {
            $referralCode = $this->referrals->saveCode($customer, $request->validated());
        } catch (UniqueConstraintViolationException) {
            return $this->codeTaken();
        }

        return response()->json(['success' => true, 'data' => ReferralCodeResource::make($referralCode)]);
    }

    /**
     * The client has verified the customer's email and phone. Pays the referrer any bonus now due and grants
     * the signup bonus when the customer qualifies (same as POST /customers/{id}/verified).
     */
    public function verified(CustomerVerificationService $verification, SignupBonusService $signupBonus, string $encryptedIdentifier): JsonResponse
    {
        $customer = $this->customer($encryptedIdentifier);

        if (! $customer) {
            return $this->customerNotFound();
        }

        $result = $verification->markVerified($customer);
        $referral = $result['referral'];

        return response()->json([
            'success' => true,
            'referred' => $referral !== null,
            'data' => $referral ? ReferralResource::make($referral->load('referred:id,name,phone_no')) : null,
            'signup_bonus' => $result['signup_bonus'] ? $signupBonus->present($result['signup_bonus']) : null,
        ]);
    }

    /**
     * The customers this customer referred, newest first, with what each one earned them.
     */
    public function customerReferrals(ListReferralsRequest $request, string $encryptedIdentifier): AnonymousResourceCollection|JsonResponse
    {
        $customer = $this->customer($encryptedIdentifier);

        if (! $customer) {
            return $this->customerNotFound();
        }

        $filters = $request->validated();

        $referrals = $this->filtered($customer->referrals()->getQuery(), $filters)
            ->with(['referred:id,name,phone_no', 'bonuses'])
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();

        return ReferralResource::collection($referrals);
    }

    public function customerStats(string $encryptedIdentifier): JsonResponse
    {
        $customer = $this->customer($encryptedIdentifier);

        if (! $customer) {
            return $this->customerNotFound();
        }

        return response()->json(['success' => true, 'data' => $this->referrals->statsFor($customer)]);
    }

    /**
     * The referral wallet balance and the bonuses paid into it, newest first.
     */
    public function wallet(Request $request, string $encryptedIdentifier): JsonResponse
    {
        $customer = $this->customer($encryptedIdentifier);

        if (! $customer) {
            return $this->customerNotFound();
        }

        $request->validate(['per_page' => 'nullable|integer|min:1|max:200']);

        $bonuses = ReferralBonus::where('customer_id', $customer->id)
            ->with(['referred:id,name', 'ledgerEntry:id,entry_id'])
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 20))
            ->withQueryString();

        return response()->json([
            'success' => true,
            'data' => [
                'customer_id' => $customer->id,
                'balance' => round((float) ($customer->referralWallet?->balance ?? 0), 2),
                'total_earned' => round((float) ReferralBonus::where('customer_id', $customer->id)->sum('amount'), 2),
                'withdrawable' => (bool) config('referrals.withdrawals.enabled'),
                'minimum_withdrawal' => (int) config('referrals.withdrawals.minimum'),
                'bonuses' => ReferralBonusResource::collection($bonuses->getCollection()),
            ],
            'pagination' => [
                'page' => $bonuses->currentPage(),
                'per_page' => $bonuses->perPage(),
                'total' => $bonuses->total(),
                'last_page' => $bonuses->lastPage(),
            ],
        ]);
    }

    /**
     * @param  Builder<Referral>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Referral>
     */
    private function filtered(Builder $query, array $filters): Builder
    {
        return $query
            ->when(($filters['status'] ?? null) === 'pending_verification', fn (Builder $query) => $query->whereNull('verified_at'))
            ->when(($filters['status'] ?? null) === 'verified', fn (Builder $query) => $query->whereNotNull('verified_at')->whereNull('first_deposited_at'))
            ->when(($filters['status'] ?? null) === 'deposited', fn (Builder $query) => $query->whereNotNull('verified_at')->whereNotNull('first_deposited_at'))
            ->when(isset($filters['from']), fn (Builder $query) => $query->where('created_at', '>=', $filters['from'].' 00:00:00'))
            ->when(isset($filters['to']), fn (Builder $query) => $query->where('created_at', '<=', $filters['to'].' 23:59:59'));
    }

    private function customer(string $identifier): ?Customer
    {
        return Customer::where('id', $identifier)->orWhere('account_no', $identifier)->first();
    }

    private function customerNotFound(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'Customer not found'], 404);
    }

    private function codeTaken(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'Referral code is already taken'], 409);
    }
}
