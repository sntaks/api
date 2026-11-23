<?php

namespace App\Domain\Jet;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;

/**
 * The closed-form flight curve M(t) = e^(k·t), unchanged from the crash server.
 */
class MultiplierCalculator
{
    public function crashDelaySeconds(float $crashMultiplier, float $k): float
    {
        return log($crashMultiplier) / $k;
    }

    public function currentMultiplier(CarbonInterface $startedAt, float $k, float $crashMultiplier, ?CarbonInterface $now = null): float
    {
        $now ??= Date::now();
        $elapsedSeconds = max(0, $startedAt->diffInMilliseconds($now, false) / 1000);

        return min(exp($k * $elapsedSeconds), $crashMultiplier);
    }
}
