<?php

namespace App\Services\Predict;

/**
 * Fixed-odds arithmetic in bcmath strings (prices to 6 places, multipliers in hundredths), never floats.
 */
class FixedOddsMath
{
    /**
     * The price we sell at: p_fair × (1 + margin), capped at the maximum offer. Truncated to 6 places.
     */
    public static function offerPrice(string $fairPrice, int $marginBp, string $maxOffer): string
    {
        $offer = bcdiv(bcmul($fairPrice, (string) (10000 + $marginBp), 10), '10000', 6);

        return bccomp($offer, $maxOffer, 6) > 0 ? bcadd($maxOffer, '0', 6) : $offer;
    }

    /**
     * 1 / p_offer in hundredths, rounded down (0.63 → 158, i.e. 1.58x).
     */
    public static function multiplierX100(string $offerPrice): int
    {
        return bccomp($offerPrice, '0', 6) > 0 ? (int) bcdiv('100', $offerPrice, 0) : 0;
    }

    /**
     * The margin for a side: the base, plus the highest ladder step whose share of the liability cap the side has
     * reached. Liability = payouts owed if the side wins − all stakes on the market (negative while the house is
     * ahead on that side).
     *
     * @param  list<array{0: float|int|string, 1: int}>  $ladder  [share of the cap, basis points added]
     */
    public static function ladderMarginBp(int $baseBp, int $liabilityMinor, int $capMinor, array $ladder): int
    {
        if ($capMinor <= 0 || $liabilityMinor <= 0) {
            return $baseBp;
        }

        $added = 0;

        foreach ($ladder as [$share, $bp]) {
            // liability / cap >= share, in integers: liability × 10000 >= cap × share × 10000
            if (bccomp(bcmul((string) $liabilityMinor, '10000'), bcmul((string) $capMinor, bcmul((string) $share, '10000', 0), 0), 0) >= 0) {
                $added = max($added, (int) $bp);
            }
        }

        return $baseBp + $added;
    }

    /**
     * Whether the offer moved against the house by more than the tolerance (a fraction) since it was quoted.
     */
    public static function movedAgainstHouse(string $quotedOffer, string $currentOffer, string $tolerance): bool
    {
        $limit = bcmul($quotedOffer, bcadd('1', $tolerance, 6), 6);

        return bccomp($currentOffer, $limit, 6) > 0;
    }
}
