<?php

namespace App\Services\Predict\Polymarket\Stream;

use App\Services\Predict\Polymarket\BookSummary;
use Illuminate\Support\Facades\Cache;

/**
 * Order books kept up to date from Polymarket's market channel. Pure state: `apply()` takes one decoded frame
 * and returns the tokens it changed. Formats verified live (2026-11-12):
 * - `book`: a full snapshot, `{event_type, asset_id, bids: [{price, size}], asks: [...]}`
 * - `price_change`: `{event_type, price_changes: [{asset_id, price, size, side: BUY|SELL, best_bid, best_ask}]}`,
 *   where `size` is the level's new size and 0 removes it
 * A frame may be one message or a list of them. Other event types are ignored.
 */
class PriceStream
{
    private const HEARTBEAT_KEY = 'predict:stream:heartbeat';

    /** @var array<string, array{bids: array<string, string>, asks: array<string, string>}> */
    private array $books = [];

    /**
     * @return list<string> the token ids whose book changed
     */
    public function apply(mixed $frame): array
    {
        $messages = is_array($frame) && array_is_list($frame) ? $frame : [$frame];
        $changed = [];

        foreach ($messages as $message) {
            if (! is_array($message)) {
                continue;
            }

            $changed = [...$changed, ...match ($message['event_type'] ?? null) {
                'book' => $this->snapshot($message),
                'price_change' => $this->changes($message),
                default => [],
            }];
        }

        return array_values(array_unique($changed));
    }

    /**
     * Whether a book snapshot has arrived for the token (a stream price is only trusted after one).
     */
    public function knows(string $tokenId): bool
    {
        return isset($this->books[$tokenId]);
    }

    /**
     * @return list<string>
     */
    public function knownTokens(): array
    {
        return array_keys($this->books);
    }

    public function summary(string $tokenId): ?BookSummary
    {
        $book = $this->books[$tokenId] ?? null;

        if ($book === null) {
            return null;
        }

        $levels = fn (array $side) => array_map(fn ($price, $size) => ['price' => (string) $price, 'size' => $size], array_keys($side), $side);

        return BookSummary::fromBook(['bids' => $levels($book['bids']), 'asks' => $levels($book['asks'])]);
    }

    public function forget(string $tokenId): void
    {
        unset($this->books[$tokenId]);
    }

    public function reset(): void
    {
        $this->books = [];
    }

    /**
     * Mark the stream healthy: the betting guard allows only `predict.fixed.max_price_age_stream_seconds` while
     * the heartbeat is fresh.
     */
    public static function beat(): void
    {
        Cache::put(self::HEARTBEAT_KEY, now()->getTimestamp(), now()->addMinute());
    }

    public static function isLive(): bool
    {
        $beat = Cache::get(self::HEARTBEAT_KEY);

        return $beat !== null && $beat >= now()->getTimestamp() - (int) config('predict.polymarket.stream_heartbeat_seconds');
    }

    /**
     * @param  array<string, mixed>  $message
     * @return list<string>
     */
    private function snapshot(array $message): array
    {
        $tokenId = (string) ($message['asset_id'] ?? '');

        if ($tokenId === '') {
            return [];
        }

        $this->books[$tokenId] = ['bids' => $this->levels($message['bids'] ?? []), 'asks' => $this->levels($message['asks'] ?? [])];

        return [$tokenId];
    }

    /**
     * @param  array<string, mixed>  $message
     * @return list<string>
     */
    private function changes(array $message): array
    {
        $changed = [];

        foreach ($message['price_changes'] ?? [] as $change) {
            $tokenId = (string) ($change['asset_id'] ?? '');
            $side = match ($change['side'] ?? null) {
                'BUY' => 'bids',
                'SELL' => 'asks',
                default => null,
            };

            // A change for a book we never had a snapshot of cannot be applied safely
            if ($side === null || ! isset($this->books[$tokenId]) || ! is_numeric($change['price'] ?? null) || ! is_numeric($change['size'] ?? null)) {
                continue;
            }

            $price = bcadd((string) $change['price'], '0', 6);

            if (bccomp((string) $change['size'], '0', 6) <= 0) {
                unset($this->books[$tokenId][$side][$price]);
            } else {
                $this->books[$tokenId][$side][$price] = (string) $change['size'];
            }

            $changed[] = $tokenId;
        }

        return $changed;
    }

    /**
     * @param  array<mixed>  $levels
     * @return array<string, string> price (6 places) => size
     */
    private function levels(array $levels): array
    {
        $book = [];

        foreach ($levels as $level) {
            if (is_numeric($level['price'] ?? null) && is_numeric($level['size'] ?? null) && bccomp((string) $level['size'], '0', 6) > 0) {
                $book[bcadd((string) $level['price'], '0', 6)] = (string) $level['size'];
            }
        }

        return $book;
    }
}
