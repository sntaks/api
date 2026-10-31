<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\FinanceListRequest;
use App\Http\Requests\StoreWinningsTaxRemittanceRequest;
use App\Http\Requests\VoidWinningsTaxRemittanceRequest;
use App\Models\WinningsTaxRemittance;
use App\Services\FinanceDateRange;
use App\Services\FinanceReportService;
use App\Services\WinningsTaxReportService;
use Illuminate\Http\JsonResponse;

/**
 * Winnings tax withheld from house-banked wins: what was charged, the monthly returns, and payments to KRA.
 */
class WinningsTaxController extends Controller
{
    public function __construct(
        private WinningsTaxReportService $winningsTax,
        private FinanceReportService $reports,
    ) {}

    public function summary(FinanceListRequest $request): JsonResponse
    {
        $range = $request->dateRange();

        return $this->respond($range, $this->winningsTax->summary($range));
    }

    public function charges(FinanceListRequest $request): JsonResponse
    {
        $range = $request->dateRange();
        $listing = $this->winningsTax->charges($range, $request->filters());

        return $this->respond($range, ['summary' => $listing->summary()] + $listing->paginate($request->page(), $request->perPage()));
    }

    public function returns(FinanceListRequest $request): JsonResponse
    {
        $range = $request->dateRange();

        return $this->respond($range, [
            'filing_day' => (int) config('finance.winnings_tax.filing_day'),
            'payable' => $this->winningsTax->payable(),
            'items' => $this->winningsTax->returns($range),
        ]);
    }

    public function remittances(FinanceListRequest $request): JsonResponse
    {
        $range = $request->dateRange();
        $listing = $this->winningsTax->remittances($range, $request->filters());

        return $this->respond($range, ['summary' => $listing->summary()] + $listing->paginate($request->page(), $request->perPage()));
    }

    /**
     * Record a payment to KRA for a period. Every unremitted row in the period, and every earlier unremitted
     * credit, is attached to it.
     */
    public function store(StoreWinningsTaxRemittanceRequest $request): JsonResponse
    {
        $remittance = $this->winningsTax->recordRemittance($request->validated(), $this->actorFor($request));

        if ($remittance === null) {
            return response()->json(['success' => false, 'message' => 'There is no winnings tax owed in that period.'], 422);
        }

        return response()->json(['success' => true, 'data' => $this->winningsTax->presentRemittance($remittance)], 201);
    }

    /**
     * Void a remittance entered in error. It is kept and flagged, and its rows become unremitted again.
     */
    public function void(VoidWinningsTaxRemittanceRequest $request, string $encryptedIdentifier): JsonResponse
    {
        $remittance = WinningsTaxRemittance::find($encryptedIdentifier);

        if (! $remittance) {
            return response()->json(['success' => false, 'message' => 'Remittance not found'], 404);
        }

        $voided = $this->winningsTax->voidRemittance($remittance->id, $request->validated('reason'), $this->actorFor($request));

        if ($voided === null) {
            return response()->json(['success' => false, 'message' => 'Remittance is already voided'], 409);
        }

        return response()->json(['success' => true, 'data' => $this->winningsTax->presentRemittance($voided)]);
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
