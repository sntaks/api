<?php

namespace App\Services\Predict;

use App\Models\PredictBet;
use App\Models\PredictMarket;
use App\Models\PredictPriceSnapshot;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Read side of prediction markets: lists, price history, the polling feed and the P&L report.
 */
class MarketQuery
{
    /**
     * Markets players may see in a country: never drafts. Without a status filter, the open and suspended ones.
     *
     * @param  array<string, mixed>  $filters
     */
    public function publicList(array $filters): LengthAwarePaginator
    {
        $country = $filters['country'] ?? config('predict.default_country');
        $statuses = isset($filters['status'])
            ? array_intersect([$filters['status']], PredictMarket::PUBLIC_STATUSES)
            : [PredictMarket::STATUS_OPEN, PredictMarket::STATUS_SUSPENDED];

        $query = PredictMarket::query()
            ->with(['pool', 'outcomes', 'event'])
            ->whereIn('status', $statuses)
            ->whereJsonContains('countries', $country)
            ->when($filters['category'] ?? null, fn (Builder $q, string $category) => $q->where('category', $category));

        return $this->sorted($query, $filters['sort'] ?? 'closing_soon')
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();
    }

    /**
     * Every market for GMS, drafts included, newest first unless sorted.
     *
     * @param  array<string, mixed>  $filters
     */
    public function adminList(array $filters): LengthAwarePaginator
    {
        $query = PredictMarket::query()
            ->with(['pool', 'product', 'outcomes', 'event'])
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters['source'] ?? null, fn (Builder $q, string $source) => $q->where('source', $source))
            ->when($filters['category'] ?? null, fn (Builder $q, string $category) => $q->where('category', $category))
            ->when($filters['country'] ?? null, fn (Builder $q, string $country) => $q->whereJsonContains('countries', $country));

        $query = isset($filters['sort']) ? $this->sorted($query, $filters['sort']) : $query->orderByDesc('id');

        return $query->paginate((int) ($filters['per_page'] ?? 50))->withQueryString();
    }

    public function findPublic(int $id): ?PredictMarket
    {
        return PredictMarket::with(['pool', 'outcomes', 'event'])
            ->whereIn('status', PredictMarket::PUBLIC_STATUSES)
            ->whereJsonContains('countries', config('predict.default_country'))
            ->find($id);
    }

    /**
     * The YES share of the pool after the seed and after each bet, thinned to at most `predict.history_points`
     * points (the last one always kept).
     *
     * @return list<array{at: string, yes_probability: float, no_probability: float}>
     */
    public function history(PredictMarket $market): array
    {
        if ($market->isImported()) {
            return $this->referenceHistory($market);
        }

        $pool = $market->pool;

        if (! $pool) {
            return [];
        }

        $yes = $pool->seed_yes_minor;
        $no = $pool->seed_no_minor;
        $points = [$this->point($market->opened_at ?? $market->created_at, $yes, $no)];

        PredictBet::where('predict_market_id', $market->id)
            ->where('status', '!=', PredictBet::STATUS_VOID)
            ->orderBy('id')
            ->select(['id', 'side', 'stake_minor', 'placed_at'])
            ->lazyById(1000)
            ->each(function (PredictBet $bet) use (&$yes, &$no, &$points) {
                $bet->side === PredictMarket::SIDE_YES ? $yes += $bet->stake_minor : $no += $bet->stake_minor;
                $points[] = $this->point($bet->placed_at, $yes, $no);
            });

        return $this->thinned($points);
    }

    /**
     * An imported market's YES reference price over the kept snapshot window.
     *
     * @return list<array{at: string, yes_probability: float, no_probability: float}>
     */
    private function referenceHistory(PredictMarket $market): array
    {
        $yes = $market->outcomes->firstWhere('side', PredictMarket::SIDE_YES);

        if (! $yes) {
            return [];
        }

        $points = PredictPriceSnapshot::where('predict_outcome_id', $yes->id)
            ->orderBy('captured_at')
            ->get(['midpoint', 'captured_at'])
            ->map(fn (PredictPriceSnapshot $snapshot) => [
                'at' => $snapshot->captured_at->toIso8601String(),
                'yes_probability' => (float) $snapshot->midpoint,
                'no_probability' => (float) bcsub('1', (string) $snapshot->midpoint, 6),
            ])
            ->all();

        return $this->thinned($points);
    }

    /**
     * At most `predict.history_points` points, the last one always kept.
     *
     * @param  list<array<string, mixed>>  $points
     * @return list<array<string, mixed>>
     */
    private function thinned(array $points): array
    {
        $limit = (int) config('predict.history_points');

        if (count($points) <= $limit) {
            return $points;
        }

        $step = (int) ceil(count($points) / ($limit - 1));
        $thinned = array_values(array_filter($points, fn ($point, int $index) => $index % $step === 0, ARRAY_FILTER_USE_BOTH));
        $thinned[] = end($points);

        return $thinned;
    }

    /**
     * Visible markets whose state or odds changed after $since, for clients that poll.
     *
     * @return Collection<int, PredictMarket>
     */
    public function changedSince(Carbon $since): Collection
    {
        return PredictMarket::query()
            ->with(['pool', 'outcomes', 'event'])
            ->whereIn('status', PredictMarket::PUBLIC_STATUSES)
            ->whereJsonContains('countries', config('predict.default_country'))
            ->where(fn (Builder $q) => $q->where('updated_at', '>', $since)
                ->orWhereHas('pool', fn (Builder $pool) => $pool->where('updated_at', '>', $since))
                ->orWhereHas('outcomes', fn (Builder $outcome) => $outcome->where('price_captured_at', '>', $since)))
            ->orderBy('id')
            ->limit(500)
            ->get();
    }

    /**
     * House P&L per market. For a settled market: players' stakes, payouts and the house result (the cut plus
     * the seed's result). For a live one: the stakes at risk and the worst case, the side that would cost most.
     * Amounts in KES.
     *
     * @param  array<string, mixed>  $filters
     */
    public function report(array $filters): LengthAwarePaginator
    {
        $totals = PredictBet::query()
            ->where('status', '!=', PredictBet::STATUS_VOID)
            ->groupBy('predict_market_id')
            ->select('predict_market_id')
            ->selectRaw("SUM(CASE WHEN side = 'yes' THEN stake_minor ELSE 0 END) AS yes_stakes")
            ->selectRaw("SUM(CASE WHEN side = 'no' THEN stake_minor ELSE 0 END) AS no_stakes")
            ->selectRaw('COALESCE(SUM(payout_minor), 0) AS payouts')
            ->selectRaw('COUNT(*) AS bets');

        $page = PredictMarket::query()
            ->with('pool')
            ->leftJoinSub($totals, 'totals', 'totals.predict_market_id', '=', 'predict_markets.id')
            ->select('predict_markets.*', 'totals.yes_stakes', 'totals.no_stakes', 'totals.payouts', 'totals.bets')
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('predict_markets.status', $status))
            ->when($filters['category'] ?? null, fn (Builder $q, string $category) => $q->where('predict_markets.category', $category))
            ->where('predict_markets.status', '!=', PredictMarket::STATUS_DRAFT)
            ->orderByDesc('predict_markets.id')
            ->paginate((int) ($filters['per_page'] ?? 50))
            ->withQueryString();

        $page->getCollection()->transform(fn (PredictMarket $market) => $this->reportLine($market));

        return $page;
    }

    /**
     * @return array<string, mixed>
     */
    private function reportLine(PredictMarket $market): array
    {
        $yesStakes = (int) $market->yes_stakes;
        $noStakes = (int) $market->no_stakes;
        $stakes = $yesStakes + $noStakes;
        $isSettled = $market->status === PredictMarket::STATUS_SETTLED;
        $pool = $market->pool;
        $line = [
            'market_id' => $market->id,
            'question' => $market->question,
            'category' => $market->category,
            'countries' => $market->countries,
            'status' => $market->status,
            'result' => $market->result,
            'bets' => (int) $market->bets,
            'stakes' => $stakes / 100,
            'seed' => $market->seed_minor / 100,
        ];

        if ($isSettled) {
            $payouts = (int) $market->payouts;
            $cut = $pool ? (int) bcdiv(bcmul((string) ($pool->yes_minor + $pool->no_minor), (string) $market->cut_bp), '10000', 0) : 0;
            $houseNet = $stakes - $payouts;

            return $line + [
                'payouts' => $payouts / 100,
                'house_net' => $houseNet / 100,
                'cut' => $cut / 100,
                'seed_result' => ($houseNet - $cut) / 100,
            ];
        }

        if (! $pool || in_array($market->status, [PredictMarket::STATUS_VOIDED], true)) {
            return $line;
        }

        $total = $pool->yes_minor + $pool->no_minor;
        $payoutIfYes = PoolMath::payoutMinor($yesStakes, PoolMath::multiplierX100($total, $pool->yes_minor, $market->cut_bp), PHP_INT_MAX);
        $payoutIfNo = PoolMath::payoutMinor($noStakes, PoolMath::multiplierX100($total, $pool->no_minor, $market->cut_bp), PHP_INT_MAX);

        return $line + [
            'payout_if_yes' => $payoutIfYes / 100,
            'payout_if_no' => $payoutIfNo / 100,
            'worst_case_house_net' => ($stakes - max($payoutIfYes, $payoutIfNo)) / 100,
        ];
    }

    private function sorted(Builder $query, string $sort): Builder
    {
        return match ($sort) {
            'new' => $query->orderByDesc('opened_at')->orderByDesc('id'),
            'trending' => $query->withCount(['bets as recent_bets' => fn (Builder $q) => $q->where('placed_at', '>=', now()->subDay())])
                ->orderByDesc('recent_bets')->orderBy('close_at'),
            default => $query->orderBy('close_at')->orderBy('id'),
        };
    }

    /**
     * @return array{at: string, yes_probability: float, no_probability: float}
     */
    private function point(?Carbon $at, int $yes, int $no): array
    {
        $yesShare = PoolMath::shareBp($yes + $no, $yes);

        return [
            'at' => ($at ?? now())->toIso8601String(),
            'yes_probability' => $yesShare / 10000,
            'no_probability' => (10000 - $yesShare) / 10000,
        ];
    }
}
