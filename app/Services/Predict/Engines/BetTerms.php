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
     * Fixed odds: a multiplier that holds for the bet, with the prices it came from.
     */
    public static function fixed(int $multiplierX100, string $fairPrice, string $offerPrice, ?int $payoutMinor = null): self
    {
        $extra = ['p_fair' => $fairPrice, 'p_offer' => $offerPrice];

        // LMSR locks the payout itself (the shares bought); fixed odds derive it from the multiplier
        if ($payoutMinor !== null) {
            $extra['payout_minor'] = $payoutMinor;
        }

        return new self($multiplierX100, false, extra: $extra);
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
