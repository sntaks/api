<?php

namespace App\Services\Concerns;

use App\Models\Game;
use App\Models\Product;
use App\Services\FinanceDateRange;
use Carbon\CarbonImmutable;

trait BuildsFinanceQueries
{
    /**
     * Customer ids to leave out of a report, empty when test customers are included.
     *
     * @return list<int>
     */
    private function excludedCustomerIds(FinanceDateRange $range): array
    {
        return $range->excludeTest ? array_values(config('finance.test_customer_ids')) : [];
    }

    /**
     * The product ids a product or game filter selects (a game means every product of it), or null when
     * neither is sent. An empty list matches nothing.
     *
     * @param  array<string, mixed>  $filters
     * @return list<int>|null
     */
    private function productIdsFor(array $filters): ?array
    {
        if (empty($filters['product']) && empty($filters['game'])) {
            return null;
        }

        return Product::query()
            ->when(! empty($filters['product']), fn ($query) => $query->where('code', $filters['product']))
            ->when(! empty($filters['game']), fn ($query) => $query->whereIn('game_id', Game::where('code', $filters['game'])->select('id')))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param  list<int>  $values
     */
    private function placeholders(array $values): string
    {
        return implode(',', array_fill(0, max(count($values), 1), '?'));
    }

    private function money(mixed $value): float
    {
        return round((float) $value, 2);
    }

    private function moment(?string $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value, config('app.timezone'))->toIso8601String();
    }
}
