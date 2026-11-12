<?php

namespace App\Services\Predict\Polymarket;

/**
 * Polymarket's CLOB API, public price reads only.
 */
class ClobClient
{
    public function __construct(private PolymarketHttp $http) {}

    /**
     * The top of the order book for each token, keyed by token id. A token without a book is missing.
     *
     * @param  list<string>  $tokenIds
     * @return array<string, BookSummary>
     */
    public function books(array $tokenIds): array
    {
        $books = [];

        foreach (array_chunk($tokenIds, (int) config('predict.polymarket.book_batch')) as $chunk) {
            $answer = $this->http->post((string) config('predict.polymarket.clob_url'), '/books', array_map(fn (string $id) => ['token_id' => $id], $chunk));

            foreach ($answer as $book) {
                if (is_array($book) && isset($book['asset_id'])) {
                    $books[(string) $book['asset_id']] = BookSummary::fromBook($book);
                }
            }
        }

        return $books;
    }
}
