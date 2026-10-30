<?php

namespace App\Services;

use App\Models\WagerRound;

/**
 * The outcome of a round settle or void: the round and what this call did, or a refusal the controller turns into
 * an error body.
 */
final readonly class WagerRoundResult
{
    /**
     * @param  array{won: int, lost: int, voided: int, already_settled: int, left_open: int}|null  $counts
     * @param  array{message: string, code: string, status: int, extra: array<string, mixed>}|null  $refusal
     * @param  list<string>|null  $skippedWinners  listed winners this settle did not pay (no longer open when locked); null for a void
     */
    private function __construct(
        public ?WagerRound $round = null,
        public ?array $counts = null,
        public ?array $refusal = null,
        public ?array $skippedWinners = null,
    ) {}

    /**
     * @param  array{won: int, lost: int, voided: int, already_settled: int, left_open: int}  $counts
     * @param  list<string>|null  $skippedWinners
     */
    public static function closed(WagerRound $round, array $counts, ?array $skippedWinners = null): self
    {
        return new self($round, $counts, skippedWinners: $skippedWinners);
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
