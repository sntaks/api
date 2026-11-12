<?php

namespace App\Services\Predict\Polymarket;

/**
 * The top of one token's order book, as decimal strings (6 places): best bid and ask, their midpoint and spread,
 * and the USD resting on the ask side within `predict.fixed.depth_band` of the best ask.
 */
final readonly class BookSummary
{
    public function __construct(
        public ?string $bestBid,
        public ?string $bestAsk,
        public ?string $midpoint,
        public ?string $spread,
        public string $depthUsd,
    ) {}

    /**
     * From a CLOB book. Levels come worst to best (verified live 2026-11-12), but the best is taken as the max
     * bid and min ask so the order does not matter.
     *
     * @param  array<string, mixed>  $book
     */
    public static function fromBook(array $book): self
    {
        $bids = array_values(array_filter(array_map(fn ($level) => $level['price'] ?? null, $book['bids'] ?? []), 'is_numeric'));
        $asks = array_filter($book['asks'] ?? [], fn ($level) => is_numeric($level['price'] ?? null) && is_numeric($level['size'] ?? null));

        $bestBid = $bids === [] ? null : array_reduce($bids, fn ($best, $price) => $best === null || bccomp($price, $best, 6) > 0 ? $price : $best);
        $bestAsk = $asks === [] ? null : array_reduce(array_column($asks, 'price'), fn ($best, $price) => $best === null || bccomp($price, $best, 6) < 0 ? $price : $best);

        $depth = '0';

        if ($bestAsk !== null) {
            $limit = bcadd($bestAsk, (string) config('predict.fixed.depth_band'), 6);

            foreach ($asks as $level) {
                if (bccomp($level['price'], $limit, 6) <= 0) {
                    $depth = bcadd($depth, bcmul($level['price'], $level['size'], 6), 6);
                }
            }
        }

        $hasBoth = $bestBid !== null && $bestAsk !== null;

        return new self(
            $bestBid !== null ? bcadd($bestBid, '0', 6) : null,
            $bestAsk !== null ? bcadd($bestAsk, '0', 6) : null,
            $hasBoth ? bcdiv(bcadd($bestBid, $bestAsk, 7), '2', 6) : null,
            $hasBoth ? bcsub($bestAsk, $bestBid, 6) : null,
            bcadd($depth, '0', 2),
        );
    }
}
