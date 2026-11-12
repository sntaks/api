<?php

namespace App\Services\Predict\Polymarket;

use Illuminate\Support\Facades\Cache;

/**
 * Stops calling Polymarket for a while after repeated failures, so an outage costs one fast refusal per call
 * instead of a timeout. State lives in the cache, shared by every worker.
 */
class CircuitBreaker
{
    private const FAILURES_KEY = 'predict:polymarket:failures';

    private const OPEN_UNTIL_KEY = 'predict:polymarket:open_until';

    public function isOpen(): bool
    {
        $openUntil = Cache::get(self::OPEN_UNTIL_KEY);

        return $openUntil !== null && $openUntil > now()->getTimestamp();
    }

    public function recordSuccess(): void
    {
        Cache::forget(self::FAILURES_KEY);
        Cache::forget(self::OPEN_UNTIL_KEY);
    }

    public function recordFailure(): void
    {
        $failures = (int) Cache::get(self::FAILURES_KEY, 0) + 1;
        Cache::put(self::FAILURES_KEY, $failures, now()->addHour());

        if ($failures >= (int) config('predict.polymarket.circuit_failures')) {
            Cache::put(self::OPEN_UNTIL_KEY, now()->addSeconds((int) config('predict.polymarket.circuit_open_seconds'))->getTimestamp(), now()->addHour());
        }
    }
}
