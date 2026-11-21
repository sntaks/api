<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StatsDashboardRequest;
use App\Services\Stats\StatsDashboardService;
use App\Services\Stats\StatsLiabilityService;
use App\Services\Stats\StatsPaymentsService;
use App\Services\Stats\StatsPlayersService;
use App\Services\Stats\StatsPromotionsService;
use Illuminate\Http\JsonResponse;

/**
 * Read-only statistics for the GMS dashboard across every game and product, from the daily rollups.
 */
class StatsDashboardController extends Controller
{
    public function __construct(
        private StatsDashboardService $stats,
        private StatsLiabilityService $liability,
        private StatsPlayersService $players,
        private StatsPromotionsService $promotions,
        private StatsPaymentsService $payments,
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

    public function players(StatsDashboardRequest $request): JsonResponse
    {
        return $this->respond($this->players->players($request->dateRange(), $request->productFilters()));
    }

    public function topPlayers(StatsDashboardRequest $request): JsonResponse
    {
        return $this->respond($this->players->top(
            $request->dateRange(),
            $request->productFilters(),
            (string) $request->input('sort', 'turnover'),
            (int) $request->input('limit', 20),
        ));
    }

    public function promotions(StatsDashboardRequest $request): JsonResponse
    {
        return $this->respond($this->promotions->promotions($request->dateRange(), $request->productFilters()));
    }

    public function payments(StatsDashboardRequest $request): JsonResponse
    {
        return $this->respond($this->payments->payments($request->dateRange()));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function respond(array $data): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data]);
    }
}
