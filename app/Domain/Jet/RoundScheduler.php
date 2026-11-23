<?php

namespace App\Domain\Jet;

use App\Models\JetRound;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Drives one round to the end, then holds the post-round pause (renewing the lease). Returns null when there is
 * nothing to run (product unavailable). Queues outbox events after every tick.
 */
class RoundScheduler
{
    public const HEARTBEAT_KEY = 'jet:loop:heartbeat';

    public function __construct(private RoundRecoveryService $recovery, private EventPublisher $publisher) {}

    public function runOnce(): ?JetRound
    {
        $temporary = ! $this->recovery->ownsLease();

        if ($temporary && ! $this->recovery->acquire()) {
            throw new JetException('LOOP_ALREADY_RUNNING', 409, 'backoff');
        }

        try {
            do {
                $round = $this->recovery->tick(true);
                $this->heartbeat();

                if (! $round) {
                    usleep(1_000_000);

                    return null;
                }

                if (! $round->isFinished()) {
                    usleep(20_000);
                }
            } while (! $round->isFinished());

            $until = microtime(true) + (int) config('jet.post_round_pause_seconds');

            while (microtime(true) < $until) {
                $this->recovery->fenced(fn () => null);
                $this->heartbeat();
                usleep(100_000);
            }

            return $round;
        } finally {
            if ($temporary) {
                $this->recovery->release();
            }
        }
    }

    private function heartbeat(): void
    {
        Cache::put(self::HEARTBEAT_KEY, now()->toIso8601String(), now()->addMinutes(2));

        try {
            $this->publisher->flush();
        } catch (Throwable $e) {
            // A queue outage never stops the game; the rows stay pending for jet:publish-outbox
            Log::warning('Jet outbox flush failed', ['exception' => $e->getMessage()]);
        }
    }
}
