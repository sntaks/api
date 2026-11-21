<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StatsDashboardRequest;
use App\Services\Stats\StatsDashboardService;
use App\Services\Stats\StatsLiabilityService;
use Illuminate\Http\JsonResponse;

/**
 * Read-only statistics for the GMS dashboard across every game and product, from the daily rollups.
 */
class StatsDashboardController extends Controller
{
    public function __construct(
        private StatsDashboardService $stats,
        private StatsLiabilityService $liability,
    ) {}

    public function dashboard(StatsDashboardRequest $request): JsonResponse
    {
        return $this->respond($this->stats->dashboard($request->statsRange(), $request->productFilters()));
    }

    public function games(StatsDashboardRequest $request): JsonResponse
    {
        return $this->respond($this->stats->games($request->dateRange(), $request->productFilters()));
    }

    public function liability(StatsDashboardRequest $request): JsonResponse
    {
        return $this->respond(['generated_at' => now()->toIso8601String()]
            + $this->liability->liability($request->productFilters(), $request->boolean('exclude_test', true)));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function respond(array $data): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data]);
    }
}
