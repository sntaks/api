<?php

namespace App\Services\Predict\Polymarket;

use App\Models\PredictMarket;
use App\Models\PredictOutcome;
use App\Models\PredictPriceSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Stores reference prices on imported markets' outcomes: fresh CLOB order books for live markets (and for a draft
 * being approved), or the prices in the Gamma payload for drafts. History is kept only when asked, i.e. for
 * markets players can see.
 */
class ReferencePriceRecorder
{
    public function __construct(private ClobClient $clob) {}

    /**
     * Fetch the order books of the markets' outcomes and record their midpoint, spread and depth. Throws PolymarketUnavailable when the CLOB
     * cannot be reached; nothing is recorded then.
     *
     * @param  Collection<int, PredictMarket>  $markets  with `outcomes` loaded
     */
    public function refresh(Collection $markets, bool $keepHistory): void
    {
        $books = $this->clob->books($markets->flatMap->outcomes->pluck('external_token_id')->unique()->values()->all());
        $now = now();

        foreach ($markets as $market) {
            foreach ($market->outcomes as $outcome) {
                $book = $books[$outcome->external_token_id] ?? null;
                $this->record($market, $outcome, $book?->midpoint, $now, $keepHistory, $book);
            }
        }
    }

    /**
     * Record books changed on the market stream (with history), and refresh the capture time of every other
     * token whose book the stream holds: an unchanged book is still the current price.
     *
     * @param  array<string, BookSummary>  $changed  by token id
     * @param  list<string>  $known  every token with a book on the stream
     */
    public function recordStream(array $changed, array $known): void
    {
        $now = now();

        if ($changed !== []) {
            PredictOutcome::with('market')->whereIn('external_token_id', array_keys($changed))->get()
                ->each(fn (PredictOutcome $outcome) => $this->record($outcome->market, $outcome, $changed[$outcome->external_token_id]->midpoint, $now, true, $changed[$outcome->external_token_id]));
        }

        $unchanged = array_values(array_diff($known, array_keys($changed)));

        $staleBefore = $now->copy()->subSeconds(PredictOutcome::maxBettingAgeSeconds());

        foreach (array_chunk($unchanged, 500) as $chunk) {
            // A price too old to bet on becomes fresh again: its multiplier is back, which is a change players see
            PredictOutcome::whereIn('external_token_id', $chunk)->where('price_reliable', true)->where('price_captured_at', '<', $staleBefore)->update(['price_changed_at' => $now]);
            PredictOutcome::whereIn('external_token_id', $chunk)->where('price_reliable', true)->update(['price_captured_at' => $now]);
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
    private function record(PredictMarket $market, PredictOutcome $outcome, ?string $price, Carbon $now, bool $keepHistory, ?BookSummary $book = null): void
    {
        $reliable = $price !== null
            && is_numeric($price)
            && bccomp($price, '0', 6) > 0
            && bccomp($price, '1', 6) < 0
            && (float) ($market->external_volume ?? 0) > 0;

        $before = $outcome->shownFigures();
        $wasFresh = $outcome->price_captured_at !== null && $outcome->price_captured_at->gte($now->copy()->subSeconds(PredictOutcome::maxBettingAgeSeconds()));

        $outcome->fill([
            'midpoint' => $reliable ? $price : null,
            'best_bid' => $book?->bestBid,
            'best_ask' => $book?->bestAsk,
            'spread' => $book?->spread,
            'depth_usd' => $book?->depthUsd,
            'price_reliable' => $reliable,
            'price_captured_at' => $now,
        ]);

        // `price_changed_at` feeds GET /predict/updates: it moves only when what players see moved, or when a
        // price that had gone too old to bet on is fresh again
        if (! $wasFresh || $outcome->shownFigures() !== $before) {
            $outcome->price_changed_at = $now;
        }

        $outcome->save();

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
