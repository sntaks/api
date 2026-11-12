<?php

namespace App\Services\Predict\Polymarket;

use Illuminate\Support\Carbon;

/**
 * Turns a Gamma market into an ImportedMarket, or null when we cannot use it: not exactly two outcomes, or
 * tokens we cannot read. The protocol comes from `version` (v1: `clobTokenIds`, a JSON-encoded string; v2:
 * `positionIds`, an array), never from which field happens to be filled.
 */
class MarketMapper
{
    /**
     * @param  array<string, mixed>  $market
     */
    public function map(array $market): ?ImportedMarket
    {
        $outcomes = $this->jsonList($market['outcomes'] ?? null);
        $prices = $this->jsonList($market['outcomePrices'] ?? null) ?? [];
        $version = $this->version($market);
        $tokens = match ($version) {
            'v1' => $this->jsonList($market['clobTokenIds'] ?? null),
            'v2' => is_array($market['positionIds'] ?? null) ? array_values($market['positionIds']) : null,
            default => null,
        };

        if (! isset($market['id'], $market['conditionId'], $market['question']) || count($outcomes ?? []) !== 2 || count($tokens ?? []) !== 2) {
            return null;
        }

        return new ImportedMarket(
            externalId: (string) $market['id'],
            conditionId: (string) $market['conditionId'],
            version: $version,
            question: mb_substr((string) $market['question'], 0, 255),
            description: $market['description'] ?? null,
            slug: $market['slug'] ?? null,
            imageUrl: isset($market['image']) ? mb_substr((string) $market['image'], 0, 500) : null,
            endAt: isset($market['endDate']) ? Carbon::parse($market['endDate']) : null,
            closed: (bool) ($market['closed'] ?? false),
            volume: isset($market['volumeNum']) ? (float) $market['volumeNum'] : null,
            liquidity: isset($market['liquidityNum']) ? (float) $market['liquidityNum'] : null,
            spread: isset($market['spread']) ? (float) $market['spread'] : null,
            yesLabel: mb_substr((string) $outcomes[0], 0, 100),
            yesTokenId: (string) $tokens[0],
            noLabel: mb_substr((string) $outcomes[1], 0, 100),
            noTokenId: (string) $tokens[1],
            yesPrice: $this->price($prices[0] ?? null),
            noPrice: $this->price($prices[1] ?? null),
        );
    }

    /**
     * `version` when present and supported; a market without one but with v1 token ids is treated as v1.
     *
     * @param  array<string, mixed>  $market
     */
    private function version(array $market): ?string
    {
        $version = $market['version'] ?? null;

        if ($version === null) {
            return isset($market['clobTokenIds']) ? 'v1' : null;
        }

        return in_array($version, ['v1', 'v2'], true) ? $version : null;
    }

    private function price(mixed $value): ?string
    {
        return is_numeric($value) ? (string) $value : null;
    }

    /**
     * @return list<mixed>|null
     */
    private function jsonList(mixed $value): ?array
    {
        if (is_array($value)) {
            return array_values($value);
        }

        if (! is_string($value)) {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? array_values($decoded) : null;
    }
}
