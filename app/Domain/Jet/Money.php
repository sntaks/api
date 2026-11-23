<?php

namespace App\Domain\Jet;

/**
 * Exact decimal helpers for two-place money and multipliers, in integer hundredths.
 */
final class Money
{
    public static function toHundredths(float|int|string $value): int
    {
        return (int) bcmul(self::decimal($value), '100', 0);
    }

    public static function fromHundredths(int $hundredths): string
    {
        return bcdiv((string) $hundredths, '100', 2);
    }

    /**
     * stake × multiplier, rounded half-up to two places (both have two places, so the product is exact first).
     */
    public static function grossPayout(string $stake, string $multiplier): string
    {
        $product = self::toHundredths($stake) * self::toHundredths($multiplier);

        return self::fromHundredths(intdiv($product + 50, 100));
    }

    private static function decimal(float|int|string $value): string
    {
        return is_string($value) ? $value : number_format((float) $value, 2, '.', '');
    }
}
