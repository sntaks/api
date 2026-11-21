<?php

namespace App\Services\Stats;

use App\Models\Product;
use App\Services\Concerns\BuildsFinanceQueries;
use App\Services\FinanceDateRange;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * /stats/promotions: what promotions cost, by type and product, and how many players they reached.
 */
class StatsPromotionsService
{
    use BuildsFinanceQueries;
    use ReadsStatsRollups;

    /** Promotion kinds tied to a product (the rest are platform promotions, product 0). */
    private const PRODUCT_KINDS = ['free_bet_value', 'free_bet_winnings', 'wager_bonus'];

    /** Kinds shown for information that are not a cost: a free bet's face value, referral bonuses earned. */
    private const NOT_COST = ['free_bet_value', 'referral_bonus'];

    public function __construct(private StatsRollupService $rollups) {}

    /**
     * @param  array<string, string>  $filters  product, game
     * @return array<string, mixed>
     */
    public function promotions(FinanceDateRange $range, array $filters): array
    {
        $productIds = $this->productIdsFor($filters);
        $warnings = $this->freshen([$range]);
        $rows = fn () => DB::table('stats_daily_player_promotions as p')
            ->whereBetween('p.date', [$range->from->toDateString(), $range->to->toDateString()])
            ->when($range->excludeTest, fn (Builder $query) => $query->where('p.is_test', false))
            ->when($productIds !== null, fn (Builder $query) => $query->whereIn('p.product_id', $productIds ?: [0]));
        $cost = fn (Builder $query) => $query->whereNotIn('p.kind', self::NOT_COST);

        $byType = $rows()->selectRaw('p.kind, SUM(p.amount) as amount, SUM(p.items) as items, COUNT(DISTINCT p.customer_id) as players')
            ->groupBy('p.kind')->orderBy('p.kind')->get()
            ->map(fn ($row) => [
                'type' => $row->kind,
                'scope' => in_array($row->kind, self::PRODUCT_KINDS, true) ? 'product' : 'platform',
                'is_cost' => ! in_array($row->kind, self::NOT_COST, true),
                'amount' => (float) $row->amount,
                'items' => (int) $row->items,
                'players' => (int) $row->players,
            ])->values()->all();

        $products = Product::with('game')->get()->keyBy('id');
        $byProduct = $rows()->where('p.product_id', '>', 0)
            ->selectRaw("p.product_id, SUM(CASE WHEN p.kind = 'free_bet_value' THEN p.amount ELSE 0 END) as free_bet_value, SUM(CASE WHEN p.kind = 'free_bet_winnings' THEN p.amount ELSE 0 END) as free_bet_winnings, SUM(CASE WHEN p.kind = 'wager_bonus' THEN p.amount ELSE 0 END) as wager_bonus, COUNT(DISTINCT CASE WHEN p.kind <> 'free_bet_value' THEN p.customer_id END) as players")
            ->groupBy('p.product_id')->get()
            ->sortBy(fn ($row) => [$products[$row->product_id]->game?->sort_order ?? PHP_INT_MAX, $products[$row->product_id]->code ?? ''])
            ->map(fn ($row) => [
                'product' => $products[$row->product_id]->code ?? null,
                'game' => $products[$row->product_id]->game?->code,
                'free_bet_value' => (float) $row->free_bet_value,
                'free_bet_winnings' => (float) $row->free_bet_winnings,
                'wager_bonus' => (float) $row->wager_bonus,
                'cost' => (float) $row->free_bet_winnings + (float) $row->wager_bonus,
                'players' => (int) $row->players,
            ])->values()->all();

        $byBucket = [];
        foreach ($cost($rows())->selectRaw('p.date, SUM(p.amount) as amount')->groupBy('p.date')->get() as $row) {
            $bucket = $range->bucketFor(CarbonImmutable::parse($row->date, config('app.timezone')));
            $byBucket[$bucket] = ($byBucket[$bucket] ?? 0) + (float) $row->amount;
        }

        $total = array_sum($byBucket);
        $active = $this->activePlayers($range, $productIds);

        return $this->rounded([
            'range' => $this->rangeOf($range),
            'totals' => [
                'cost' => (float) $total,
                'players_reached' => $cost($rows())->distinct()->count('p.customer_id'),
                'active_players' => $active,
                'cost_per_active_player' => $active > 0 ? $total / $active : null,
            ],
            'by_type' => $byType,
            'by_product' => $byProduct,
            'series' => array_map(fn (string $bucket) => ['date' => $bucket, 'cost' => (float) ($byBucket[$bucket] ?? 0)], $range->buckets()),
            'generated_at' => $this->generatedAt($range),
            'warnings' => $warnings,
            'notes' => StatsVocabulary::promotionNotes($productIds !== null),
        ]);
    }
}
