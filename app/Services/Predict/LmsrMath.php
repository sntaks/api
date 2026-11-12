<?php

namespace App\Services\Predict;

use InvalidArgumentException;

/**
 * Logarithmic market scoring rule (LMSR) for a binary market, in bcmath strings: shares q per side (each pays
 * KES 1 if its side wins) and liquidity b, both in KES. exp and ln are computed here to SCALE places with range
 * reduction, so no money maths ever touches a float.
 *
 * Cost C(q) = b·ln(e^{q_yes/b} + e^{q_no/b}); price_yes = 1 / (1 + e^{(q_no − q_yes)/b}). The house's worst-case
 * loss is b·ln(1 / opening price of the side that wins), at most b·ln(1 / min(p0, 1 − p0)).
 */
class LmsrMath
{
    private const SCALE = 30;

    /**
     * Opening shares for an opening YES probability p0: q_no = 0, q_yes = b·ln(p0 / (1 − p0)).
     *
     * @return array{yes: string, no: string}
     */
    public static function opening(string $probability, string $liquidity): array
    {
        $odds = bcdiv($probability, bcsub('1', $probability, self::SCALE), self::SCALE);

        return ['yes' => self::round(bcmul($liquidity, self::ln($odds), self::SCALE)), 'no' => '0.000000'];
    }

    /**
     * The current price of a side: 1 / (1 + e^{(q_other − q_side)/b}), to 6 places.
     */
    public static function price(string $qSide, string $qOther, string $liquidity): string
    {
        $exponent = bcdiv(bcsub($qOther, $qSide, self::SCALE), $liquidity, self::SCALE);

        return self::roundHalfUp(bcdiv('1', bcadd('1', self::exp($exponent), self::SCALE), self::SCALE), 6);
    }

    /**
     * C(q) = b·ln(e^{q_yes/b} + e^{q_no/b}), as max(q) + b·ln(1 + e^{−|q_yes − q_no|/b}) so it never overflows.
     */
    public static function cost(string $qYes, string $qNo, string $liquidity): string
    {
        $high = bccomp($qYes, $qNo, self::SCALE) >= 0 ? $qYes : $qNo;
        $gap = bcsub($qYes, $qNo, self::SCALE);
        $gap = bccomp($gap, '0', self::SCALE) < 0 ? bcmul($gap, '-1', self::SCALE) : $gap;
        $tail = self::ln(bcadd('1', self::exp(bcdiv(bcmul($gap, '-1', self::SCALE), $liquidity, self::SCALE)), self::SCALE));

        return bcadd($high, bcmul($liquidity, $tail, self::SCALE), self::SCALE);
    }

    /**
     * Shares bought on a side for a net spend s: Δ = b·ln(e^{s/b}·(1 + e^{d/b}) − e^{d/b}), d = q_other − q_side.
     * It solves C(q_side + Δ, q_other) − C(q_side, q_other) = s. Rounded down to 6 places.
     */
    public static function sharesFor(string $netSpend, string $qSide, string $qOther, string $liquidity): string
    {
        $eD = self::exp(bcdiv(bcsub($qOther, $qSide, self::SCALE), $liquidity, self::SCALE));
        $eS = self::exp(bcdiv($netSpend, $liquidity, self::SCALE));
        $inner = bcsub(bcmul($eS, bcadd('1', $eD, self::SCALE), self::SCALE), $eD, self::SCALE);

        return bcmul($liquidity, self::ln($inner), 6);
    }

    /**
     * The most the house can lose before fees: b·ln(1 / min(p0, 1 − p0)), in KES to 2 places.
     */
    public static function worstCaseLoss(string $probability, string $liquidity): string
    {
        $smaller = bccomp($probability, '0.5', 6) <= 0 ? $probability : bcsub('1', $probability, 6);

        return bcmul($liquidity, self::ln(bcdiv('1', $smaller, self::SCALE)), 2);
    }

    /**
     * e^x: halve x until |x| ≤ 0.5, sum the Taylor series, square back. e^{−x} = 1 / e^x.
     */
    public static function exp(string $x): string
    {
        if (bccomp($x, '0', self::SCALE) < 0) {
            return bcdiv('1', self::exp(bcmul($x, '-1', self::SCALE)), self::SCALE);
        }

        $halvings = 0;

        while (bccomp($x, '0.5', self::SCALE) > 0) {
            $x = bcdiv($x, '2', self::SCALE);
            $halvings++;
        }

        $sum = '1';
        $term = '1';

        for ($i = 1; $i < 100; $i++) {
            $term = bcdiv(bcmul($term, $x, self::SCALE), (string) $i, self::SCALE);

            if (bccomp($term, '0', self::SCALE) === 0) {
                break;
            }

            $sum = bcadd($sum, $term, self::SCALE);
        }

        for ($i = 0; $i < $halvings; $i++) {
            $sum = bcmul($sum, $sum, self::SCALE);
        }

        return $sum;
    }

    /**
     * ln x for x > 0: take square roots until x is near 1, sum 2·atanh((x − 1)/(x + 1)), scale back by 2^k.
     */
    public static function ln(string $x): string
    {
        if (bccomp($x, '0', self::SCALE) <= 0) {
            throw new InvalidArgumentException('ln is only defined for positive numbers');
        }

        $roots = 0;

        while (bccomp($x, '1.1', self::SCALE) > 0 || bccomp($x, '0.9', self::SCALE) < 0) {
            $x = bcsqrt($x, self::SCALE);
            $roots++;
        }

        $y = bcdiv(bcsub($x, '1', self::SCALE), bcadd($x, '1', self::SCALE), self::SCALE);
        $ySquared = bcmul($y, $y, self::SCALE);
        $power = $y;
        $sum = '0';

        for ($n = 1; $n < 400; $n += 2) {
            $term = bcdiv($power, (string) $n, self::SCALE);

            if (bccomp($term, '0', self::SCALE) === 0) {
                break;
            }

            $sum = bcadd($sum, $term, self::SCALE);
            $power = bcmul($power, $ySquared, self::SCALE);
        }

        return bcmul(bcmul($sum, '2', self::SCALE), bcpow('2', (string) $roots, 0), self::SCALE);
    }

    private static function round(string $value): string
    {
        return self::roundHalfUp($value, 6);
    }

    private static function roundHalfUp(string $value, int $places): string
    {
        $half = '0.'.str_repeat('0', $places).'5';

        return bccomp($value, '0', self::SCALE) < 0
            ? bcsub($value, $half, $places)
            : bcadd($value, $half, $places);
    }
}
