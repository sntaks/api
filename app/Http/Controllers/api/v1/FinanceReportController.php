<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\FinanceReportRequest;
use App\Services\FinanceDateRange;
use App\Services\FinanceReconciliationService;
use App\Services\FinanceReportService;
use App\Services\FinanceTaxService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class FinanceReportController extends Controller
{
    /** Seconds to cache a report over a window that is already closed. */
    private const CLOSED_WINDOW_TTL = 3600;

    /** Days a reconciliation covers when no range is given. */
    private const RECONCILIATION_DEFAULT_DAYS = 30;

    public function __construct(
        private FinanceReportService $reports,
        private FinanceReconciliationService $reconciliation,
        private FinanceTaxService $taxes,
    ) {}

    public function summary(FinanceReportRequest $request): JsonResponse
    {
        $range = $request->dateRange();

        return $this->respond('summary', $range, fn () => $this->reports->summary($range->excludeTest));
    }

    public function incomeStatement(FinanceReportRequest $request): JsonResponse
    {
        $range = $request->dateRange();

        $filters = $request->productFilters();

        return $this->respond('income-statement', $range, fn () => $this->withMeta($range, $this->reports->incomeStatement($range, $filters)), $filters);
    }

    public function cashFlow(FinanceReportRequest $request): JsonResponse
    {
        $range = $request->dateRange();

        return $this->respond('cash-flow', $range, fn () => $this->withMeta($range, $this->reports->cashFlow($range)));
    }

    public function balanceSheet(FinanceReportRequest $request): JsonResponse
    {
        $range = $request->dateRange();
        $asOf = $request->input('as_of');

        $sheet = $this->reports->balanceSheet($asOf);
        if ($sheet === null) {
            return response()->json(['success' => false, 'message' => 'There is no snapshot for that date.'], 404);
        }

        return $this->respond('balance-sheet', $range, fn () => $sheet, ['as_of' => $asOf], $asOf === null ? null : self::CLOSED_WINDOW_TTL);
    }

    public function trialBalance(FinanceReportRequest $request): JsonResponse
    {
        $range = $request->dateRange();

        $filters = $request->productFilters();

        return $this->respond('trial-balance', $range, fn () => $this->withMeta($range, $this->reports->trialBalance($range, $filters)), $filters);
    }

    public function taxes(FinanceReportRequest $request): JsonResponse
    {
        $range = $request->dateRange();

        return $this->respond('taxes', $range, fn () => $this->taxes->estimate($range));
    }

    public function reconciliation(FinanceReportRequest $request): JsonResponse
    {
        $range = $request->dateRange(self::RECONCILIATION_DEFAULT_DAYS);

        return $this->respond('reconciliation', $range, fn () => $this->withMeta($range, $this->reconciliation->run($range)));
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function withMeta(FinanceDateRange $range, array $report): array
    {
        return ['meta' => $this->reports->meta($range)] + $report;
    }

    /**
     * Cache a report. Windows that include today are cached briefly, closed windows for an hour.
     *
     * @param  array<string, mixed>  $extra  Anything besides the range that changes the result.
     */
    private function respond(string $name, FinanceDateRange $range, Closure $build, array $extra = [], ?int $ttl = null): JsonResponse
    {
        $key = 'finance:'.$name.':'.md5(json_encode([
            $range->from->toDateString(), $range->to->toDateString(), $range->groupBy, $range->excludeTest, $extra,
        ]));

        $data = Cache::remember(
            $key,
            $ttl ?? $range->cacheSeconds() ?? self::CLOSED_WINDOW_TTL,
            fn () => ['generated_at' => now()->toIso8601String()] + $build()
        );

        return response()->json(['success' => true, 'data' => $data]);
    }
}
