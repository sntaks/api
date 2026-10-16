<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\FinanceDepositListRequest;
use App\Http\Requests\FinanceListRequest;
use App\Services\CustomerService;
use App\Services\FinanceDateRange;
use App\Services\FinanceDisputeReportService;
use App\Services\FinanceExpenseService;
use App\Services\FinanceGameReportService;
use App\Services\FinanceLedgerReportService;
use App\Services\FinanceListing;
use App\Services\FinancePaymentReportService;
use App\Services\FinancePromotionReportService;
use App\Services\FinanceReferralReportService;
use App\Services\FinanceReportService;
use App\Services\HouseFundingService;
use Illuminate\Http\JsonResponse;

class FinanceDrilldownController extends Controller
{
    /** Days a customer statement or top-customers report covers when no range is given. */
    private const DEFAULT_DAYS = 30;

    public function __construct(
        private FinancePaymentReportService $payments,
        private FinanceLedgerReportService $ledger,
        private FinanceGameReportService $games,
        private FinanceReportService $reports,
        private CustomerService $customers,
        private FinanceExpenseService $expenses,
        private FinanceDisputeReportService $disputes,
        private FinanceReferralReportService $referrals,
        private FinancePromotionReportService $promotions,
        private HouseFundingService $houseFunding,
    ) {}

    public function deposits(FinanceDepositListRequest $request): JsonResponse
    {
        return $this->paged($request, $this->payments->deposits($request->dateRange(), $request->filters()));
    }

    public function withdrawals(FinanceListRequest $request): JsonResponse
    {
        return $this->paged($request, $this->payments->withdrawals($request->dateRange(), $request->filters()));
    }

    public function purchases(FinanceListRequest $request): JsonResponse
    {
        return $this->paged($request, $this->payments->purchases($request->dateRange(), $request->filters()));
    }

    public function games(FinanceListRequest $request): JsonResponse
    {
        return $this->paged($request, $this->games->games($request->dateRange(), $request->filters()));
    }

    public function competitions(FinanceListRequest $request): JsonResponse
    {
        return $this->paged($request, $this->games->competitions($request->dateRange(), $request->filters()));
    }

    /**
     * Open game and competition wallets nothing has happened on for a while, as of now.
     */
    public function openWallets(FinanceListRequest $request): JsonResponse
    {
        return $this->paged($request, $this->games->openWallets($request->dateRange(), $request->filters()));
    }

    public function ledger(FinanceListRequest $request): JsonResponse
    {
        return $this->paged($request, $this->ledger->ledger($request->dateRange(), $request->filters()));
    }

    public function adjustments(FinanceListRequest $request): JsonResponse
    {
        return $this->paged($request, $this->ledger->adjustments($request->dateRange(), $request->filters()));
    }

    public function expenses(FinanceListRequest $request): JsonResponse
    {
        return $this->paged($request, $this->expenses->listing($request->dateRange(), $request->filters()));
    }

    /**
     * Owner money credited to the house wallet (house funding), by the day it was recorded.
     */
    public function houseFunding(FinanceListRequest $request): JsonResponse
    {
        return $this->paged($request, $this->houseFunding->listing($request->dateRange(), $request->filters()));
    }

    public function disputes(FinanceListRequest $request): JsonResponse
    {
        return $this->paged($request, $this->disputes->listing($request->dateRange(), $request->filters()));
    }

    /**
     * Referral programme totals for the range (bonuses earned, payouts) and the current position.
     */
    public function referrals(FinanceListRequest $request): JsonResponse
    {
        $range = $request->dateRange();

        return $this->respond($range, $this->referrals->summary($range));
    }

    public function referralBonuses(FinanceListRequest $request): JsonResponse
    {
        return $this->paged($request, $this->referrals->bonuses($request->dateRange(), $request->filters()));
    }

    public function referralWithdrawals(FinanceListRequest $request): JsonResponse
    {
        return $this->paged($request, $this->referrals->withdrawals($request->dateRange(), $request->filters()));
    }

    /**
     * Promotion credits granted in the range (the signup bonus), with totals and the budget used.
     */
    public function promotions(FinanceListRequest $request): JsonResponse
    {
        return $this->paged($request, $this->promotions->credits($request->dateRange(), $request->filters()));
    }

    public function topCustomers(FinanceListRequest $request): JsonResponse
    {
        $range = $request->dateRange(self::DEFAULT_DAYS);
        $listing = $this->ledger->topCustomers($range, $request->filters());

        return $this->respond($range, ['summary' => $listing->summary(), 'items' => $listing->all()]);
    }

    public function customerStatement(FinanceListRequest $request, string $encryptedIdentifier): JsonResponse
    {
        $customer = $this->customers->getCustomer($encryptedIdentifier);

        if (! $customer) {
            return response()->json(['success' => false, 'message' => 'Customer not found'], 404);
        }

        $range = $request->dateRange(self::DEFAULT_DAYS);
        $statement = $this->ledger->customerStatement($customer, $range, $request->page(), $request->perPage());

        if ($statement === null) {
            return response()->json(['success' => false, 'message' => 'Customer has no wallet'], 404);
        }

        return $this->respond($range, $statement);
    }

    private function paged(FinanceListRequest $request, FinanceListing $listing): JsonResponse
    {
        return $this->respond($request->dateRange(), ['summary' => $listing->summary()] + $listing->paginate($request->page(), $request->perPage()));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function respond(FinanceDateRange $range, array $data): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ['generated_at' => now()->toIso8601String(), 'meta' => $this->reports->meta($range)] + $data,
        ]);
    }
}
