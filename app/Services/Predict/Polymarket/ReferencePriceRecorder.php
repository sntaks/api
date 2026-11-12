<?php

namespace App\Services\Predict\Polymarket;

use App\Models\PredictMarket;
use App\Models\PredictOutcome;
use App\Models\PredictPriceSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Stores reference prices on imported markets' outcomes: fresh CLOB midpoints for live markets (and for a draft
 * being approved), or the prices in the Gamma payload for drafts. History is kept only when asked, i.e. for
 * markets players can see.
 */
class ReferencePriceRecorder
{
    public function __construct(private ClobClient $clob) {}

    /**
     * Fetch midpoints for the markets' outcomes and record them. Throws PolymarketUnavailable when the CLOB
     * cannot be reached; nothing is recorded then.
     *
     * @param  Collection<int, PredictMarket>  $markets  with `outcomes` loaded
     */
    public function refresh(Collection $markets, bool $keepHistory): void
    {
        $prices = $this->clob->midpoints($markets->flatMap->outcomes->pluck('external_token_id')->unique()->values()->all());
        $now = now();

        foreach ($markets as $market) {
            foreach ($market->outcomes as $outcome) {
                $this->record($market, $outcome, $prices[$outcome->external_token_id] ?? null, $now, $keepHistory);
            }
        }
    }

    /**
     * Record a draft's prices from the Gamma market it was imported from (no extra call, no history).
     */
    public function recordFromGamma(PredictMarket $market, ImportedMarket $imported): void
    {
        $now = now();
        $prices = [PredictMarket::SIDE_YES => $imported->yesPrice, PredictMarket::SIDE_NO => $imported->noPrice];

        foreach ($market->outcomes()->get() as $outcome) {
            $this->record($market, $outcome, $prices[$outcome->side] ?? null, $now, false);
        }
    }

    /**
     * A price is reliable when Polymarket quoted it, it lies strictly between 0 and 1, and the market has traded
     * (an untraded book can show a placeholder 0.5).
     */
    private function record(PredictMarket $market, PredictOutcome $outcome, ?string $price, Carbon $now, bool $keepHistory): void
    {
        $reliable = $price !== null
            && is_numeric($price)
            && bccomp($price, '0', 6) > 0
            && bccomp($price, '1', 6) < 0
            && (float) ($market->external_volume ?? 0) > 0;

        $outcome->update([
            'midpoint' => $reliable ? $price : null,
            'price_reliable' => $reliable,
            'price_captured_at' => $now,
        ]);

        if ($keepHistory && $reliable && $outcome->side === PredictMarket::SIDE_YES) {
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
}
