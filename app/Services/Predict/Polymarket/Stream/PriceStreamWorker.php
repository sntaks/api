<?php

namespace App\Services\Predict\Polymarket\Stream;

use App\Models\PredictMarket;
use App\Models\PredictOutcome;
use App\Services\Predict\Polymarket\ReferencePriceRecorder;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Keeps live imported markets priced from Polymarket's market channel:
 * - subscribes to the tokens of open and suspended imported markets, refreshing the set every
 *   `stream_resubscribe_seconds`, and sends PING every 10 s;
 * - once a second, records the tops of changed books and refreshes `price_captured_at` of every token with a
 *   known book (an unchanged book is still current while the connection is healthy), then beats the heartbeat;
 * - on a lost connection, drops the books (no stale price is ever written) and reconnects with backoff.
 * The 20 s REST price sync keeps running as the fallback and still suspends markets that fail the guards.
 */
class PriceStreamWorker
{
    private PriceStream $stream;

    /** @var array<string, true> */
    private array $subscribed = [];

    /** @var array<string, true> */
    private array $dirty = [];

    /**
     * @param  Closure(): MarketConnection  $connect
     */
    public function __construct(private Closure $connect, private ReferencePriceRecorder $recorder)
    {
        $this->stream = new PriceStream;
    }

    /**
     * Run until $shouldStop says so or $maxSeconds passed (Supervisor restarts it).
     *
     * @param  Closure(): bool  $shouldStop
     * @return array{connections: int, messages: int, flushes: int}
     */
    public function run(int $maxSeconds, Closure $shouldStop): array
    {
        $stats = ['connections' => 0, 'messages' => 0, 'flushes' => 0];
        $deadline = microtime(true) + $maxSeconds;
        $backoff = 1;

        while (microtime(true) < $deadline && ! $shouldStop()) {
            try {
                $connection = ($this->connect)();
                $stats['connections']++;
                $backoff = 1;
                $this->session($connection, $deadline, $shouldStop, $stats);
                $connection->close();

                return $stats;
            } catch (StreamDisconnected $e) {
                Log::warning('Prediction price stream disconnected', ['error' => $e->getMessage()]);
                $this->stream->reset();
                $this->subscribed = [];
                $this->dirty = [];
                usleep((int) (min($backoff, max(0, $deadline - microtime(true))) * 1_000_000));
                $backoff = min($backoff * 2, 30);
            }
        }

        return $stats;
    }

    /**
     * @param  Closure(): bool  $shouldStop
     * @param  array{connections: int, messages: int, flushes: int}  $stats
     */
    private function session(MarketConnection $connection, float $deadline, Closure $shouldStop, array &$stats): void
    {
        $this->resubscribe($connection, initial: true);
        $lastPing = $lastFlush = $lastResubscribe = microtime(true);

        while (microtime(true) < $deadline && ! $shouldStop()) {
            $text = $connection->receive();

            if ($text !== null && $text !== 'PONG') {
                $stats['messages']++;

                foreach ($this->stream->apply(json_decode($text, true)) as $tokenId) {
                    $this->dirty[$tokenId] = true;
                }
            }

            $now = microtime(true);

            if ($now - $lastPing >= 10) {
                $connection->send('PING');
                $lastPing = $now;
            }

            if ($now - $lastResubscribe >= (int) config('predict.polymarket.stream_resubscribe_seconds')) {
                $this->resubscribe($connection);
                $lastResubscribe = $now;
            }

            if ($now - $lastFlush >= 1) {
                $this->flush();
                $stats['flushes']++;
                $lastFlush = $now;
            }
        }
    }

    /**
     * Subscribe to tokens of markets that became live and unsubscribe from those no longer live.
     */
    private function resubscribe(MarketConnection $connection, bool $initial = false): void
    {
        $wanted = array_fill_keys($this->liveTokens(), true);
        $add = array_keys(array_diff_key($wanted, $this->subscribed));
        $drop = array_keys(array_diff_key($this->subscribed, $wanted));

        if ($initial) {
            // The first message names the channel; Polymarket then sends a book snapshot per token
            $connection->send((string) json_encode(['assets_ids' => $add, 'type' => 'market']));
        } elseif ($add !== []) {
            $connection->send((string) json_encode(['operation' => 'subscribe', 'assets_ids' => $add]));
        }

        if ($drop !== []) {
            $connection->send((string) json_encode(['operation' => 'unsubscribe', 'assets_ids' => $drop]));

            foreach ($drop as $tokenId) {
                $this->stream->forget($tokenId);
                unset($this->dirty[$tokenId]);
            }
        }

        $this->subscribed = $wanted;
    }

    /**
     * Record changed books and refresh the capture time of every token with a known book.
     */
    private function flush(): void
    {
        $known = $this->stream->knownTokens();

        if ($known === []) {
            return;
        }

        $books = [];

        foreach (array_keys($this->dirty) as $tokenId) {
            if ($summary = $this->stream->summary($tokenId)) {
                $books[$tokenId] = $summary;
            }
        }

        $this->recorder->recordStream($books, $known);
        $this->dirty = [];
        PriceStream::beat();
    }

    /**
     * @return list<string>
     */
    private function liveTokens(): array
    {
        return PredictOutcome::whereHas('market', fn ($q) => $q
            ->where('source', PredictMarket::SOURCE_POLYMARKET)
            ->whereIn('status', [PredictMarket::STATUS_OPEN, PredictMarket::STATUS_SUSPENDED]))
            ->pluck('external_token_id')
            ->unique()
            ->values()
            ->all();
    }
}
