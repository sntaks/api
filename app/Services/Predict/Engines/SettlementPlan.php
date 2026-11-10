<?php

namespace App\Services\Predict\Engines;

/**
 * What a resolved market pays: every winning bet's payout (minor units), keyed by predict bet id.
 */
final readonly class SettlementPlan
{
    /**
     * @param  array<int, array{reference: string, payout_minor: int}>  $winners
     */
    public function __construct(
        public string $result,
        public int $finalMultiplierX100,
        public array $winners,
        public int $userStakeMinor,
        public int $payoutMinor,
    ) {}

    public function houseNetMinor(): int
    {
        return $this->userStakeMinor - $this->payoutMinor;
    }
}
