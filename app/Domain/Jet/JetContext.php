<?php

namespace App\Domain\Jet;

use App\Models\ApiKey;
use App\Models\Product;
use App\Services\GameWagerKey;
use RuntimeException;

/**
 * The jet_classic product and the jet game key the engine posts its wagers with, in-process.
 */
class JetContext
{
    public function __construct(private GameWagerKey $gameWagerKey) {}

    public function wagerKey(): ApiKey
    {
        return $this->gameWagerKey->for((string) config('jet.game_code'));
    }

    public function product(): Product
    {
        return $this->productOrNull() ?? throw new RuntimeException('Kadi Jet needs the '.config('jet.product_code').' product');
    }

    /**
     * The product, or null before the catalogue step has created it.
     */
    public function productOrNull(): ?Product
    {
        return Product::with('game')->where('code', config('jet.product_code'))->first();
    }

    /**
     * Whether a new round may start: the product exists and it and its game are active.
     */
    public function isAvailable(): bool
    {
        return $this->productOrNull()?->isAvailable() ?? false;
    }

    public function productCode(): string
    {
        return (string) config('jet.product_code');
    }
}
