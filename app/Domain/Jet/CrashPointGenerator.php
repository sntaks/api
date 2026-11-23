<?php

namespace App\Domain\Jet;

/**
 * hmac-sha256-52bit-v1, unchanged from the crash server so published proofs keep verifying: HMAC-SHA256 of
 * "client_seed:nonce" keyed by the server seed, first 13 hex digits / 2^52, raw = max(1, floor(100·(1−edge)/(1−U))/100).
 * The caller caps the result at the round's max multiplier.
 */
class CrashPointGenerator
{
    public function generate(string $serverSeed, string $clientSeed, int $nonce, float $houseEdge): float
    {
        $hash = hash_hmac('sha256', "{$clientSeed}:{$nonce}", $serverSeed);
        $u = hexdec(substr($hash, 0, 13)) / 2 ** 52;

        return max(1.00, floor((1 - $houseEdge) / (1 - $u) * 100) / 100);
    }
}
