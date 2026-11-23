<?php

namespace App\Domain\Jet;

use App\Models\JetRound;

/**
 * Confirmed flight progress for display: one row read, no fence. Null when there is no safe running sample;
 * a worker running late past the crash never shows the hidden crash point.
 */
class PresentationService
{
    public function __construct(private MultiplierCalculator $calculator) {}

    /**
     * @return array<string, mixed>
     */
    public function read(): array
    {
        $round = JetRound::latest('id')->first();
        $at = now();
        $value = null;

        if ($round?->status === JetRound::STATUS_RUNNING && $round->started_at) {
            $raw = $this->calculator->currentMultiplier($round->started_at, (float) $round->acceleration_k, (float) $round->crash_multiplier, $at);

            if (is_finite($raw) && $raw < (float) $round->crash_multiplier) {
                $value = number_format(floor($raw * 100) / 100, 2, '.', '');
            }
        }

        return [
            'schema_version' => 1,
            'round_number' => $round?->round_number,
            'status' => $round?->status,
            'revision' => (int) ($round->revision ?? 0),
            'sample_id' => (int) $at->getTimestampMs(),
            'sampled_at' => $at->toISOString(),
            'multiplier' => $value,
        ];
    }
}
