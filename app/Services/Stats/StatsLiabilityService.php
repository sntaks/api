<?php

namespace App\Services\Stats;

use App\Models\PredictBet;
use App\Models\PredictMarket;
use App\Models\Product;
use App\Models\Wager;
use App\Services\Concerns\BuildsFinanceQueries;
use App\Services\Predict\PoolMath;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Open exposure right now, per product: wagers placed and not settled yet, what they staked and the most
 * the house could have to pay on them. Read live from wagers and prediction bets (never from rollups).
 */
class StatsLiabilityService
{
    use BuildsFinanceQueries;

    /**
     * @param  array<string, string>  $filters  product, game
     * @return array{open_wagers: int, open_stakes: float, open_free_bet_value: float, open_potential_payout: float, by_product: list<array<string, mixed>>, top_positions: list<array<string, mixed>>, notes: list<string>}
     */
    public function liability(array $filters, bool $excludeTest = true): array
    {
        $productIds = $this->productIdsFor($filters);
        $excluded = $excludeTest ? array_values(array_map('intval', config('finance.test_customer_ids'))) : [];
        $open = fn () => DB::table('wagers as w')
            ->where('w.status', Wager::STATUS_OPEN)
            ->where('w.is_tombstone', false)
            ->when($productIds !== null, fn (Builder $query) => $query->whereIn('w.product_id', $productIds ?: [0]))
            ->when($excluded !== [], fn (Builder $query) => $query->where(fn (Builder $inner) => $inner->whereNull('w.customer_id')->orWhereNotIn('w.customer_id', $excluded)));

        $potential = "CAST(JSON_UNQUOTE(JSON_EXTRACT(w.metadata, '$.potential_payout')) AS DECIMAL(14,2))";
        $wagers = $open()
            ->selectRaw("w.product_id, COUNT(*) as wagers, SUM(w.stake) as stakes, SUM(w.free_bet_amount) as free_bet_value, SUM(COALESCE({$potential}, 0)) as potential, SUM({$potential} IS NOT NULL) as with_potential")
            ->groupBy('w.product_id')
            ->get()
            ->keyBy('product_id');

        $predict = $this->predictWorstCase($productIds, $excluded);
        $products = Product::with('game')->whereKey($wagers->keys()->merge(array_keys($predict)))->get()
            ->sortBy(fn (Product $product) => [$product->game?->sort_order ?? PHP_INT_MAX, $product->code]);

        $rows = $products->map(function (Product $product) use ($wagers, $predict) {
            $row = $wagers[$product->id] ?? null;
            $isPredict = isset($predict[$product->id]);

            return [
                'product' => $product->code,
                'game' => $product->game?->code,
                'open_wagers' => (int) ($row->wagers ?? 0),
                'open_stakes' => round((float) ($row->stakes ?? 0), 2),
                'open_free_bet_value' => round((float) ($row->free_bet_value ?? 0), 2),
                'open_potential_payout' => round($isPredict ? $predict[$product->id] : (float) ($row->potential ?? 0), 2),
                'potential_payout_basis' => $isPredict ? 'worst_side' : 'reported',
                'wagers_with_potential_payout' => $isPredict ? (int) ($row->wagers ?? 0) : (int) ($row->with_potential ?? 0),
            ];
        })->values()->all();

        $top = $open()
            ->join('products as p', 'p.id', '=', 'w.product_id')
            ->leftJoin('predict_bets as pb', 'pb.wager_id', '=', 'w.id')
            ->selectRaw("w.reference, p.code as product, w.stake, w.free_bet_amount, w.placed_at, pb.predict_market_id, pb.side, COALESCE({$potential}, FLOOR(pb.stake_minor * pb.estimated_multiplier_x100 / 100) / 100) as potential_payout")
            ->orderByRaw("COALESCE({$potential}, FLOOR(pb.stake_minor * pb.estimated_multiplier_x100 / 100) / 100, w.stake + w.free_bet_amount) DESC")
            ->limit((int) config('stats.liability.top_positions'))
            ->get()
            ->map(fn ($row) => [
                'reference' => $row->reference,
                'product' => $row->product,
                'stake' => round((float) $row->stake, 2),
                'free_bet_amount' => round((float) $row->free_bet_amount, 2),
                'potential_payout' => $row->potential_payout === null ? null : round((float) $row->potential_payout, 2),
                'predict_market_id' => $row->predict_market_id === null ? null : (int) $row->predict_market_id,
                'side' => $row->side,
                'placed_at' => $this->moment($row->placed_at),
            ])->all();

        return [
            'open_wagers' => array_sum(array_column($rows, 'open_wagers')),
            'open_stakes' => round(array_sum(array_column($rows, 'open_stakes')), 2),
            'open_free_bet_value' => round(array_sum(array_column($rows, 'open_free_bet_value')), 2),
            'open_potential_payout' => round(array_sum(array_column($rows, 'open_potential_payout')), 2),
            'by_product' => $rows,
            'top_positions' => $top,
            'notes' => [
                'Open wagers are placed and not settled yet. open_stakes is their cash stakes; free bets are counted apart in open_free_bet_value.',
                'For prediction markets open_potential_payout is the worst case: per market, what the house would pay if the side that costs it most wins (pool markets at the current pool multiplier, capped at the market maximum payout; fixed-odds and LMSR bets at the odds they were taken at), summed over markets.',
                'For other products open_potential_payout adds up the potential_payout the game sent in the wager metadata; wagers_with_potential_payout says how many open wagers carried one. It is 0 for games that do not send it.',
                'top_positions are the largest open wagers by potential payout (by stake when none is known). They carry no player details.',
            ],
        ];
    }

    /**
     * Per prediction product, the sum over its unsettled markets of the payout if the side that costs the
     * house most wins.
     *
     * @param  list<int>|null  $productIds
     * @param  list<int>  $excluded
     * @return array<int, float> Keyed by product id, in KES.
     */
    private function predictWorstCase(?array $productIds, array $excluded): array
    {
        $sides = DB::table('predict_bets as b')
            ->join('wagers as w', 'w.id', '=', 'b.wager_id')
            ->join('predict_markets as m', 'm.id', '=', 'b.predict_market_id')
            ->leftJoin('predict_pools as pool', 'pool.predict_market_id', '=', 'm.id')
            ->where('b.status', PredictBet::STATUS_OPEN)
            ->where('w.status', Wager::STATUS_OPEN)
            ->when($productIds !== null, fn (Builder $query) => $query->whereIn('m.product_id', $productIds ?: [0]))
            ->when($excluded !== [], fn (Builder $query) => $query->whereNotIn('b.customer_id', $excluded))
            ->selectRaw('m.id as market_id, m.product_id, m.engine, m.cut_bp, m.cut_on, m.max_payout_minor, pool.yes_minor, pool.no_minor, b.side, COUNT(*) as bets, SUM(b.stake_minor) as stakes_minor, SUM(LEAST(FLOOR(b.stake_minor * b.estimated_multiplier_x100 / 100), m.max_payout_minor)) as fixed_payout_minor')
            ->groupBy('m.id', 'm.product_id', 'm.engine', 'm.cut_bp', 'm.cut_on', 'm.max_payout_minor', 'pool.yes_minor', 'pool.no_minor', 'b.side')
            ->get();

        $markets = [];
        foreach ($sides as $row) {
            if ($row->engine === PredictMarket::ENGINE_POOL && $row->yes_minor !== null) {
                $total = (int) $row->yes_minor + (int) $row->no_minor;
                $sideTotal = $row->side === PredictMarket::SIDE_YES ? (int) $row->yes_minor : (int) $row->no_minor;
                $multiplier = PoolMath::multiplierX100($total, $sideTotal, (int) $row->cut_bp, $row->cut_on !== PredictMarket::CUT_ON_POOL);
                $payout = min(intdiv((int) $row->stakes_minor * $multiplier, 100), (int) $row->bets * (int) $row->max_payout_minor);
            } else {
                $payout = (int) $row->fixed_payout_minor;
            }

            $markets[$row->market_id]['product_id'] = (int) $row->product_id;
            $markets[$row->market_id]['worst'] = max($markets[$row->market_id]['worst'] ?? 0, $payout);
        }

        $byProduct = [];
        foreach ($markets as $market) {
            $byProduct[$market['product_id']] = ($byProduct[$market['product_id']] ?? 0.0) + $market['worst'] / 100;
        }

        return $byProduct;
    }
}
