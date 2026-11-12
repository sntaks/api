<?php

namespace App\Services\Predict\Polymarket;

use Illuminate\Support\Facades\Cache;

/**
 * Polymarket's Gamma API: tags, and open events with their markets (keyset pagination).
 */
class GammaClient
{
    public function __construct(private PolymarketHttp $http) {}

    /**
     * A tag's id from its slug, cached for a day.
     */
    public function tagId(string $slug): ?string
    {
        return Cache::remember("predict:polymarket:tag:{$slug}", now()->addDay(), function () use ($slug) {
            $tag = $this->http->get($this->baseUrl(), '/tags/slug/'.rawurlencode($slug));

            return isset($tag['id']) ? (string) $tag['id'] : null;
        });
    }

    /**
     * One page of open events under a tag. `next_cursor` is absent on the last page.
     *
     * @return array{events: list<array<string, mixed>>, next_cursor: ?string}
     */
    public function openEvents(string $tagId, ?string $cursor = null): array
    {
        $page = $this->http->get($this->baseUrl(), '/events/keyset', array_filter([
            // Without closed=false this endpoint also returns closed events
            'closed' => 'false',
            'limit' => (int) config('predict.polymarket.page_size'),
            // One tag per call, sent as a plain tag_id=… (the API's array form, exploded)
            'tag_id' => $tagId,
            'after_cursor' => $cursor,
        ], fn ($value) => $value !== null));

        return [
            'events' => array_values($page['events'] ?? []),
            'next_cursor' => isset($page['next_cursor']) && $page['next_cursor'] !== '' ? (string) $page['next_cursor'] : null,
        ];
    }

    /**
     * Markets by Gamma id, open or closed, at most `predict.polymarket.lookup_batch` ids per call. Ids go as a
     * repeated `id=` (the only form `closed=any` accepts; `id[0]=` is refused, verified 2026-11-12).
     *
     * @param  list<string>  $ids
     * @return list<array<string, mixed>>
     */
    public function marketsById(array $ids): array
    {
        $markets = [];

        foreach (array_chunk($ids, (int) config('predict.polymarket.lookup_batch')) as $chunk) {
            $query = 'closed=any&limit='.count($chunk).'&'.implode('&', array_map(fn (string $id) => 'id='.rawurlencode($id), $chunk));
            $page = $this->http->get($this->baseUrl(), '/markets/keyset?'.$query);
            $markets = [...$markets, ...array_values($page['markets'] ?? [])];
        }

        return $markets;
    }

    private function baseUrl(): string
    {
        return (string) config('predict.polymarket.gamma_url');
    }
}
