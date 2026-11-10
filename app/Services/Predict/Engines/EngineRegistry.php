<?php

namespace App\Services\Predict\Engines;

use App\Models\PredictMarket;
use InvalidArgumentException;

/**
 * The pricing engine a market uses. Fixed odds (A) and LMSR (C) plug in here in later phases.
 */
class EngineRegistry
{
    public function __construct(private PoolEngine $pool) {}

    public function for(PredictMarket $market): PricingEngine
    {
        return match ($market->engine) {
            PredictMarket::ENGINE_POOL => $this->pool,
            default => throw new InvalidArgumentException("Unknown pricing engine {$market->engine}"),
        };
    }
}
