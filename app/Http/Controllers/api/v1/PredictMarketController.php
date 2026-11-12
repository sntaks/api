<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Requests\Predict\EstimatePredictBetRequest;
use App\Http\Requests\Predict\ListPredictMarketsRequest;
use App\Http\Resources\Predict\PredictMarketResource;
use App\Models\PredictMarket;
use App\Services\Predict\BetService;
use App\Services\Predict\MarketQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Markets as players see them (Kadi site key, or GMS). Drafts are never shown.
 */
class PredictMarketController extends PredictController
{
    public function __construct(private MarketQuery $markets, private BetService $bets) {}

    public function index(ListPredictMarketsRequest $request): JsonResponse
    {
        return $this->pagedResponse($this->markets->publicList($request->validated()), fn (PredictMarket $market) => PredictMarketResource::make($market)->resolve());
    }

    public function show(string $id): JsonResponse
    {
        $market = $this->findPublic($id);

        if (! $market) {
            return $this->refusal('market_not_found', 'No market has that id', 404);
        }

        return response()->json(['success' => true, 'data' => PredictMarketResource::make($market)->resolve()]);
    }

    public function history(string $id): JsonResponse
    {
        $market = $this->findPublic($id);

        if (! $market) {
            return $this->refusal('market_not_found', 'No market has that id', 404);
        }

        return response()->json(['success' => true, 'data' => ['market_id' => $market->id, 'points' => $this->markets->history($market)]]);
    }

    /**
     * The estimated multiplier and payout for a stake; final odds are only fixed when the market closes.
     */
    public function estimate(EstimatePredictBetRequest $request, string $id): JsonResponse
    {
        $market = $this->findPublic($id);

        if (! $market) {
            return $this->refusal('market_not_found', 'No market has that id', 404);
        }

        if ($market->status !== PredictMarket::STATUS_OPEN) {
            return $this->refusal('market_not_open', 'This market is not taking bets', 409, ['status' => $market->status]);
        }

        $data = $request->validated();

        return $this->respond($this->bets->estimate($market, $data['side'], $data['stake']), fn (array $estimate) => $estimate);
    }

    /**
     * Polling feed: markets whose state or odds changed after `since`. Pass back `server_time` as the next since.
     */
    public function updates(Request $request): JsonResponse
    {
        $data = $request->validate(['since' => ['required', 'date', 'after:-1 day']]);
        $serverTime = now();

        return response()->json(['success' => true, 'data' => [
            'server_time' => $serverTime->toIso8601String(),
            'markets' => $this->markets->changedSince(Carbon::parse($data['since']))
                ->map(fn (PredictMarket $market) => PredictMarketResource::make($market)->resolve())
                ->values(),
        ]]);
    }

    private function findPublic(string $id): ?PredictMarket
    {
        $marketId = $this->routeId($id);

        return $marketId === null ? null : $this->markets->findPublic($marketId);
    }
}
