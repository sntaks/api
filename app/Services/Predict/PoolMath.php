<?php

namespace App\Services\Predict;

/**
 * Pari-mutuel pool arithmetic. Money in minor units, the cut and probabilities in basis points, multipliers in
 * hundredths (158 = 1.58x). All of it in bcmath strings, so a large pool never overflows and nothing is a float.
 * Multipliers and payouts round down: the remainder stays with the house.
 */
class PoolMath
{
    private const BP = 10000;

    /**
     * Opening totals from the seed: yes = seed × p (rounded down), no = the rest, so the two always add up.
     *
     * @return array{yes: int, no: int}
     */
    public static function seedSplit(int $seedMinor, int $probabilityBp): array
    {
        $yes = (int) bcdiv(bcmul((string) $seedMinor, (string) $probabilityBp), (string) self::BP, 0);

        return ['yes' => $yes, 'no' => $seedMinor - $yes];
    }

    /**
     * T × (1 − c) / side, in hundredths, rounded down.
     */
    public static function multiplierX100(int $totalMinor, int $sideMinor, int $cutBp): int
    {
        if ($sideMinor <= 0) {
            return 0;
        }

        $numerator = bcmul((string) $totalMinor, (string) (self::BP - $cutBp));
        $denominator = bcmul((string) $sideMinor, '100');

        return (int) bcdiv($numerator, $denominator, 0);
    }

    /**
     * The multiplier a side would show after a further stake on it: (T + s)(1 − c) / (side + s).
     */
    public static function estimateX100(int $totalMinor, int $sideMinor, int $stakeMinor, int $cutBp): int
    {
        return self::multiplierX100($totalMinor + $stakeMinor, $sideMinor + $stakeMinor, $cutBp);
    }

    /**
     * The share of the pool a side holds, in basis points (rounded down).
     */
    public static function shareBp(int $totalMinor, int $sideMinor): int
    {
        return $totalMinor > 0 ? (int) bcdiv(bcmul((string) $sideMinor, (string) self::BP), (string) $totalMinor, 0) : 0;
    }

    /**
     * stake × multiplier, rounded down to the minor unit and capped at the market's maximum payout.
     */
    public static function payoutMinor(int $stakeMinor, int $multiplierX100, int $maxPayoutMinor): int
    {
        $payout = (int) bcdiv(bcmul((string) $stakeMinor, (string) $multiplierX100), '100', 0);

        return min($payout, $maxPayoutMinor);
    }

    /**
     * The largest stake on a side that keeps its multiplier at or above the minimum, from
     * (T + s)(1 − c) ≥ m(side + s). Because a multiplier floors to a whole hundredth and m is one, the bound
     * is exact. Returns 0 when the side is already full, and PHP_INT_MAX when the minimum can never bind.
     */
    public static function maxStakeForMinimum(int $totalMinor, int $sideMinor, int $cutBp, int $minMultiplierX100): int
    {
        $keep = self::BP - $cutBp;
        $slope = $minMultiplierX100 * 100 - $keep;

        if ($slope <= 0) {
            return PHP_INT_MAX;
        }

        $headroom = bcsub(bcmul((string) $totalMinor, (string) $keep), bcmul((string) $sideMinor, (string) ($minMultiplierX100 * 100)));

        if (bccomp($headroom, '0') <= 0) {
            return 0;
        }

        return (int) bcdiv($headroom, (string) $slope, 0);
    }
}
