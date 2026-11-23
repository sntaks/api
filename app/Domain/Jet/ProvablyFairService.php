<?php

namespace App\Domain\Jet;

use Illuminate\Support\Str;

class ProvablyFairService
{
    public const ALGORITHM = 'hmac-sha256-52bit-v1';

    public function __construct(private CrashPointGenerator $generator) {}

    public function generateServerSeed(): string
    {
        return Str::random(40);
    }

    public function generateClientSeed(): string
    {
        return Str::random(16);
    }

    public function hashServerSeed(string $serverSeed): string
    {
        return hash('sha256', $serverSeed);
    }

    public function crashPoint(string $serverSeed, string $clientSeed, int $nonce, float $houseEdge, float $cap): float
    {
        return min($this->generator->generate($serverSeed, $clientSeed, $nonce, $houseEdge), $cap);
    }

    /**
     * Checks the seed commitment first, then recomputes the capped crash point.
     */
    public function verify(string $serverSeed, string $serverSeedHash, string $clientSeed, int $nonce, float $houseEdge, float $cap, float $crashMultiplier): bool
    {
        if (! hash_equals($serverSeedHash, $this->hashServerSeed($serverSeed))) {
            return false;
        }

        return abs($this->crashPoint($serverSeed, $clientSeed, $nonce, $houseEdge, $cap) - $crashMultiplier) < 0.0001;
    }
}
