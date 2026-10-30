<?php

namespace App\Services;

use App\Models\Wager;
use App\Models\WagerSettlement;

/**
 * The outcome of a wager operation: the wager and the player's balance after it, whether it was a replay, or
 * a refusal the controller turns into an error body.
 */
final readonly class WagerResult
{
    /**
     * @param  array{balance: float, wallet_version: int}|null  $balance
     * @param  array{message: string, code: string, status: int, extra: array<string, mixed>}|null  $refusal
     */
    private function __construct(
        public ?Wager $wager = null,
        public ?array $balance = null,
        public bool $replayed = false,
        public ?array $refusal = null,
        public ?WagerSettlement $settlement = null,
        public bool $created = false,
    ) {}

    /**
     * A settlement of a wager. `$created` is true when the settle also created the wager (a tombstone).
     */
    public static function settled(Wager $wager, WagerSettlement $settlement, bool $replayed = false, bool $created = false): self
    {
        return new self($wager, null, $replayed, null, $settlement, $created);
    }

    /**
     * @param  array{balance: float, wallet_version: int}  $balance
     */
    public static function placed(Wager $wager, array $balance, bool $replayed = false): self
    {
        return new self($wager, $balance, $replayed);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public static function refused(string $code, string $message, int $status, array $extra = []): self
    {
        return new self(refusal: ['message' => $message, 'code' => $code, 'status' => $status, 'extra' => $extra]);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }
}
