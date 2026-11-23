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
        return Product::with('game')->where('code', config('jet.product_code'))->first()
            ?? throw new RuntimeException('Kadi Jet needs the '.config('jet.product_code').' product');
    }

    public function productCode(): string
    {
        return (string) config('jet.product_code');
    }
}
