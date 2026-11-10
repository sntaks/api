<?php

namespace App\Services\Predict\Engines;

/**
 * The terms a stake gets, or the reason it is refused.
 */
final readonly class BetTerms
{
    /**
     * @param  array<string, mixed>  $extra
     */
    private function __construct(
        public ?int $multiplierX100,
        public bool $isEstimate,
        public ?string $refusalCode = null,
        public ?string $refusalMessage = null,
        public int $refusalStatus = 422,
        public array $extra = [],
    ) {}

    public static function estimate(int $multiplierX100): self
    {
        return new self($multiplierX100, true);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public static function refused(string $code, string $message, int $status = 422, array $extra = []): self
    {
        return new self(null, false, $code, $message, $status, $extra);
    }

    public function isRefused(): bool
    {
        return $this->refusalCode !== null;
    }
}
