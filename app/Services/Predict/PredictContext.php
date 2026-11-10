<?php

namespace App\Services\Predict;

use App\Models\ApiKey;
use App\Models\Game;
use App\Models\PredictMarket;
use App\Models\Product;
use RuntimeException;

/**
 * The game key and products prediction markets post wagers under. The module places and settles in-process
 * through WagerService, which only accepts a game key of the product's own game.
 */
class PredictContext
{
    public function wagerKey(): ApiKey
    {
        $gameId = Game::idFor((string) config('predict.game_code'));
        $key = $gameId ? ApiKey::where('game_id', $gameId)->where('scope', ApiKey::SCOPE_GAME)->where('is_active', true)->orderBy('id')->first() : null;

        if (! $key) {
            throw new RuntimeException('Prediction markets need an active game API key for game '.config('predict.game_code'));
        }

        return $key;
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
