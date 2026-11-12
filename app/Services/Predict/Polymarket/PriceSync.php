<?php

namespace App\Services\Predict\Polymarket;

use App\Exceptions\PolymarketUnavailable;
use App\Models\PredictMarket;
use App\Models\PredictOutcome;
use App\Services\Predict\MarketService;
use Illuminate\Support\Collection;

/**
 * Refreshes the reference prices of live imported markets (open and suspended) and keeps their history. Drafts
 * are priced by the event sync from Gamma, and freshly when approved. A market whose price is missing, never
 * traded, out of range or stale is suspended; it reopens by itself once prices are usable again, unless an admin
 * suspended it. When Polymarket cannot be reached, every open imported market is suspended: we never show or use
 * a stale price.
 */
class PriceSync
{
    public function __construct(private ReferencePriceRecorder $recorder, private MarketService $markets) {}

    /**
     * @return array{markets: int, priced: int, suspended: int, resumed: int, unavailable: bool}
     */
    public function sync(): array
    {
        $markets = PredictMarket::with('outcomes')
            ->where('source', PredictMarket::SOURCE_POLYMARKET)
            ->whereIn('status', [PredictMarket::STATUS_OPEN, PredictMarket::STATUS_SUSPENDED])
            ->get();

        $counts = ['markets' => $markets->count(), 'priced' => 0, 'suspended' => 0, 'resumed' => 0, 'unavailable' => false];

        if ($markets->isEmpty()) {
            return $counts;
        }

        try {
            $this->recorder->refresh($markets, keepHistory: true);
        } catch (PolymarketUnavailable) {
            $counts['unavailable'] = true;
            $counts['suspended'] = $this->suspendAll($markets, 'Polymarket unavailable');

            return $counts;
        }

        foreach ($markets as $market) {
            $usable = $market->outcomes->count() === 2 && $market->outcomes->every(fn (PredictOutcome $outcome) => $outcome->hasUsablePrice());
            $counts['priced'] += $usable ? 1 : 0;

            if (! $usable && $market->status === PredictMarket::STATUS_OPEN) {
                $counts['suspended'] += $this->markets->suspendForPrice($market, 'No usable reference price')->isRefused() ? 0 : 1;
            }

            if ($usable && $market->status === PredictMarket::STATUS_SUSPENDED && $market->suspension_cause === PredictMarket::SUSPENDED_FOR_PRICE) {
                $counts['resumed'] += $this->markets->resumeAfterPrice($market)->isRefused() ? 0 : 1;
            }
        }

        return $counts;
    }

    /**
     * @param  Collection<int, PredictMarket>  $markets
     */
    private function suspendAll(Collection $markets, string $reason): int
    {
        return $markets->where('status', PredictMarket::STATUS_OPEN)
            ->sum(fn (PredictMarket $market) => $this->markets->suspendForPrice($market, $reason)->isRefused() ? 0 : 1);
    }
}
