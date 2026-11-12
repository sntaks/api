<?php

namespace App\Services\Predict\Polymarket;

use Illuminate\Support\Carbon;

/**
 * A binary Gamma market, reduced to what we store.
 */
final readonly class ImportedMarket
{
    public function __construct(
        public string $externalId,
        public string $conditionId,
        public string $version,
        public string $question,
        public ?string $description,
        public ?string $slug,
        public ?string $imageUrl,
        public ?Carbon $endAt,
        public bool $closed,
        public ?float $volume,
        public ?float $liquidity,
        public ?float $spread,
        public string $yesLabel,
        public string $yesTokenId,
        public string $noLabel,
        public string $noTokenId,
        public ?string $yesPrice = null,
        public ?string $noPrice = null,
    ) {}
}
