<?php

namespace App\Services\Predict\Polymarket;

use App\Exceptions\PolymarketUnavailable;
use App\Models\PredictMarket;
use App\Models\PredictOutcome;
use App\Models\PredictPriceSnapshot;
use App\Services\Predict\MarketService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Refreshes the reference prices of imported markets (drafts, open and suspended). A market whose price is
 * missing, never traded, out of range or stale is suspended; it reopens by itself once prices are usable again,
 * unless an admin suspended it. When Polymarket cannot be reached, every open imported market is suspended:
 * we never show or use a stale price.
 */
class PriceSync
{
    public function __construct(private ClobClient $clob, private MarketService $markets) {}

    /**
     * @return array{markets: int, priced: int, suspended: int, resumed: int, unavailable: bool}
     */
    public function sync(): array
    {
        $markets = PredictMarket::with('outcomes')
            ->where('source', PredictMarket::SOURCE_POLYMARKET)
            ->whereIn('status', [PredictMarket::STATUS_DRAFT, PredictMarket::STATUS_OPEN, PredictMarket::STATUS_SUSPENDED])
            ->get();

        $counts = ['markets' => $markets->count(), 'priced' => 0, 'suspended' => 0, 'resumed' => 0, 'unavailable' => false];

        if ($markets->isEmpty()) {
            return $counts;
        }

        try {
            $prices = $this->clob->midpoints($markets->flatMap->outcomes->pluck('external_token_id')->unique()->values()->all());
        } catch (PolymarketUnavailable) {
            $counts['unavailable'] = true;
            $counts['suspended'] = $this->suspendAll($markets, 'Polymarket unavailable');

            return $counts;
        }

        $now = now();

        foreach ($markets as $market) {
            foreach ($market->outcomes as $outcome) {
                $this->record($market, $outcome, $prices[$outcome->external_token_id] ?? null, $now);
            }

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
     * A price is reliable when Polymarket quoted it, it lies strictly between 0 and 1, and the market has traded
     * (an untraded book can show a placeholder 0.5).
     */
    private function record(PredictMarket $market, PredictOutcome $outcome, ?string $price, Carbon $now): void
    {
        $reliable = $price !== null
            && bccomp($price, '0', 6) > 0
            && bccomp($price, '1', 6) < 0
            && (float) ($market->external_volume ?? 0) > 0;

        $outcome->update([
            'midpoint' => $price,
            'price_reliable' => $reliable,
            'price_captured_at' => $now,
        ]);

        if ($reliable && $outcome->side === PredictMarket::SIDE_YES) {
            $this->snapshot($outcome, $price, $now);
        }
    }

    /**
     * Keep a history point when the price moved enough or the last point is old enough.
     */
    private function snapshot(PredictOutcome $outcome, string $price, Carbon $now): void
    {
        $last = PredictPriceSnapshot::where('predict_outcome_id', $outcome->id)->latest('captured_at')->first();
        $moved = $last === null || abs((float) $price - (float) $last->midpoint) >= (float) config('predict.polymarket.snapshot_min_move');
        $due = $last === null || $last->captured_at->lte($now->copy()->subSeconds((int) config('predict.polymarket.snapshot_every_seconds')));

        if ($moved || $due) {
            PredictPriceSnapshot::create(['predict_outcome_id' => $outcome->id, 'midpoint' => $price, 'captured_at' => $now]);
        }
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
