<?php

namespace App\Services\Predict\Engines;

use App\Models\PredictMarket;
use InvalidArgumentException;

/**
 * The pricing engine a market uses: pool (B) or LMSR (C) for custom markets, fixed odds (A) or pool (B) for
 * imported ones (`predict.polymarket.engine`).
 */
class EngineRegistry
{
    public function __construct(private PoolEngine $pool, private FixedOddsEngine $fixed, private LmsrEngine $lmsr) {}

    public function for(PredictMarket $market): PricingEngine
    {
        return match ($market->engine) {
            PredictMarket::ENGINE_POOL => $this->pool,
            PredictMarket::ENGINE_REFERENCE => $this->fixed,
            PredictMarket::ENGINE_LMSR => $this->lmsr,
            default => throw new InvalidArgumentException("Unknown pricing engine {$market->engine}"),
        };
    }
}
