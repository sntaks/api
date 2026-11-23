<?php

namespace App\Services\Predict;

use App\Models\ApiKey;
use App\Models\PredictMarket;
use App\Models\Product;
use App\Services\GameWagerKey;
use RuntimeException;

/**
 * The game key and products prediction markets post wagers under. The module places and settles in-process
 * through WagerService, which only accepts a game key of the product's own game.
 */
class PredictContext
{
    public function __construct(private GameWagerKey $gameWagerKey) {}

    public function wagerKey(): ApiKey
    {
        return $this->gameWagerKey->for((string) config('predict.game_code'));
    }

    public function productFor(string $engine): Product
    {
        $code = config("predict.products.{$engine}");
        $product = $code ? Product::where('code', $code)->first() : null;

        if (! $product) {
            throw new RuntimeException("No product is configured for the {$engine} engine");
        }

        return $product;
    }

    public function productCode(PredictMarket $market): string
    {
        return $market->product->code;
    }
}
