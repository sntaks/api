<?php

namespace App\Domain\Jet;

use App\Models\JetRound;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;

/**
 * Confirmed flight progress for display, with no fence. The latest round's row is cached for at most
 * jet.presentation_cache_ms (and dropped on every round save), so the database load does not grow with the
 * number of polling clients. The multiplier is still computed per request; null when there is no safe running
 * sample, so a stale row or a worker running late past the crash never shows the hidden crash point.
 */
class PresentationService
{
    public const CACHE_KEY = 'jet:presentation:round';

    public function __construct(private MultiplierCalculator $calculator) {}

    /**
     * @return array<string, mixed>
     */
    public function read(): array
    {
        $round = $this->latestRound();
        $at = now();
        $value = null;

        if ($round !== null && $round['status'] === JetRound::STATUS_RUNNING && $round['started_at'] !== null) {
            $crash = (float) $round['crash_multiplier'];
            $raw = $this->calculator->currentMultiplier(Date::createFromTimestampMs($round['started_at']), (float) $round['acceleration_k'], $crash, $at);

            if (is_finite($raw) && $raw < $crash) {
                $value = number_format(floor($raw * 100) / 100, 2, '.', '');
            }
        }

        return [
            'schema_version' => 1,
            'round_number' => $round['round_number'] ?? null,
            'status' => $round['status'] ?? null,
            'revision' => (int) ($round['revision'] ?? 0),
            'sample_id' => (int) $at->getTimestampMs(),
            'sampled_at' => $at->toISOString(),
            'multiplier' => $value,
        ];
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array{round_number: int, status: string, revision: int, started_at: int|null, acceleration_k: string, crash_multiplier: string}|null
     */
    private function latestRound(): ?array
    {
        $nowMs = (int) now()->getTimestampMs();
        $cached = Cache::get(self::CACHE_KEY);

        $maxAgeMs = (int) config('jet.presentation_cache_ms');

        if (is_array($cached) && $nowMs - $cached['cached_at_ms'] < $maxAgeMs) {
            return $cached['round'];
        }

        $round = JetRound::latest('id')->first();
        $row = $round === null ? null : [
            'round_number' => $round->round_number,
            'status' => $round->status,
            'revision' => (int) $round->revision,
            'started_at' => $round->started_at ? (int) $round->started_at->getTimestampMs() : null,
            'acceleration_k' => (string) $round->acceleration_k,
            'crash_multiplier' => (string) $round->crash_multiplier,
        ];

        // Store TTLs are whole seconds; the age check above is what bounds staleness
        Cache::put(self::CACHE_KEY, ['round' => $row, 'cached_at_ms' => $nowMs], intdiv($maxAgeMs, 1000) + 2);

        return $row;
    }
}
