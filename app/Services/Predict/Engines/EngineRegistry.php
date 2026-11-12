<?php

namespace App\Services\Predict\Engines;

use App\Models\PredictMarket;
use InvalidArgumentException;

/**
 * The pricing engine a market uses: pool (B) for custom markets, fixed odds (A) for imported ones. LMSR (C) would
 * plug in here.
 */
class EngineRegistry
{
    public function __construct(private PoolEngine $pool, private FixedOddsEngine $fixed) {}

    public function for(PredictMarket $market): PricingEngine
    {
        return match ($market->engine) {
            PredictMarket::ENGINE_POOL => $this->pool,
            PredictMarket::ENGINE_REFERENCE => $this->fixed,
            default => throw new InvalidArgumentException("Unknown pricing engine {$market->engine}"),
        };
    }
}
