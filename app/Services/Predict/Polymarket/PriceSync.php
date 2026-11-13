<?php

namespace App\Services\Predict\Polymarket;

use App\Exceptions\PolymarketUnavailable;
use App\Models\PredictMarket;
use App\Models\PredictOutcome;
use App\Services\Predict\MarketService;
use Illuminate\Support\Collection;

/**
 * Refreshes the reference prices of live fixed-odds imported markets (open and suspended; an imported pool
 * market has its own odds and is never priced here) and keeps their history. Drafts
 * are priced by the event sync from Gamma, and freshly when approved. An open market failing a betting guard (no
 * usable price, price too old, spread too wide, book too thin) is suspended; it reopens by itself once prices are usable again, unless an admin
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
            ->where('engine', PredictMarket::ENGINE_REFERENCE)
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
            $failure = $market->outcomes->count() === 2
                ? $market->outcomes->map(fn (PredictOutcome $outcome) => $outcome->bettingGuardFailure())->filter()->first()
                : 'no_price';
            $usable = $failure === null;
            $counts['priced'] += $usable ? 1 : 0;

            if (! $usable && $market->status === PredictMarket::STATUS_OPEN) {
                $counts['suspended'] += $this->markets->suspendForPrice($market, "Betting guard failed: {$failure}")->isRefused() ? 0 : 1;
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
