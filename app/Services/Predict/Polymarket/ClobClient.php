<?php

namespace App\Services\Predict\Polymarket;

/**
 * Polymarket's CLOB API, public price reads only.
 */
class ClobClient
{
    public function __construct(private PolymarketHttp $http) {}

    /**
     * Midpoints for the tokens, as decimal strings keyed by token id. A token without an order book is
     * missing from the result.
     *
     * @param  list<string>  $tokenIds
     * @return array<string, string>
     */
    public function midpoints(array $tokenIds): array
    {
        $prices = [];

        foreach (array_chunk($tokenIds, (int) config('predict.polymarket.midpoint_batch')) as $chunk) {
            $answer = $this->http->post((string) config('predict.polymarket.clob_url'), '/midpoints', array_map(fn (string $id) => ['token_id' => $id], $chunk));

            foreach ($answer as $tokenId => $price) {
                if (is_string($tokenId) && is_numeric($price)) {
                    $prices[$tokenId] = (string) $price;
                }
            }
        }

        return $prices;
    }
}
