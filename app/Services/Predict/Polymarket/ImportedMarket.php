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
        public ?Carbon $gameStartAt,
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
        public ?string $resolutionStatus = null,
    ) {}

    /**
     * The winning side once Polymarket has resolved the market, from two signals that must agree: the UMA status
     * says resolved and the final prices are exactly 1 and 0 (verified live 2026-11-12). Null otherwise.
     */
    public function resolvedSide(): ?string
    {
        if (! $this->closed || $this->resolutionStatus !== 'resolved') {
            return null;
        }

        return match ([$this->yesPrice, $this->noPrice]) {
            ['1', '0'] => 'yes',
            ['0', '1'] => 'no',
            default => null,
        };
    }
}
