<?php

use App\Services\Predict\PoolMath;

/*
 * Amounts here are whole units (the maths is unit-free); multipliers are hundredths. Pool markets take the cut from
 * the losing side only; the brief's numbers (seed 1,000, p = 0.30, cut 0.13 of the whole pool) are the old rule,
 * which markets settled before 2026-11-13 keep.
 */

it('opens a 1,000 seed at p 0.30 with a 20% cut on winnings as YES 2.86x and NO 1.34x', function () {
    $seed = PoolMath::seedSplit(1000, 3000);

    expect($seed)->toBe(['yes' => 300, 'no' => 700])
        // 1 + 700 × 0.8 / 300 = 2.866…, 1 + 300 × 0.8 / 700 = 1.342…
        ->and(PoolMath::multiplierX100(1000, 300, 2000, true))->toBe(286)
        ->and(PoolMath::multiplierX100(1000, 700, 2000, true))->toBe(134);
});

it('pays a 95% favourite more than its stake and the 5% long shot 16.2x', function () {
    expect(PoolMath::multiplierX100(1000, 950, 2000, true))->toBe(104)
        ->and(PoolMath::multiplierX100(1000, 50, 2000, true))->toBe(1620)
        // Under the old rule the favourite paid under 1x
        ->and(PoolMath::multiplierX100(1000, 950, 1000, false))->toBe(94);
});

it('takes the cut from the losing side only', function () {
    // T = 1,800: YES 600 wins, NO 1,200 loses, cut 20%
    $multiplier = PoolMath::multiplierX100(1800, 600, 2000, true);
    $paid = PoolMath::payoutMinor(600, $multiplier, PHP_INT_MAX);

    expect($multiplier)->toBe(260)
        ->and($paid)->toBe(1560)
        ->and(1800 - $paid)->toBe(1200 * 20 / 100);
});

it('still loses at most the seed: 500 on YES against the opening pool loses 350', function () {
    $multiplier = PoolMath::multiplierX100(1500, 800, 2000, true);
    $userPayout = PoolMath::payoutMinor(500, $multiplier, PHP_INT_MAX);

    expect($multiplier)->toBe(170)
        ->and($userPayout)->toBe(850)
        ->and(500 - $userPayout)->toBe(-350)
        ->and(500 - $userPayout)->toBeGreaterThanOrEqual(-1000);
});

it('estimates a stake as 1 + O(1 − c) / (side + s)', function () {
    // 1 + 700 × 0.8 / 800 = 1.70
    expect(PoolMath::estimateX100(1000, 300, 500, 2000, true))->toBe(170);
});

it('finds the largest stake that keeps a side at the minimum multiplier', function (int $minimum) {
    // T = 10,000, YES = 5,000, cut 20%
    $largest = PoolMath::maxStakeForMinimum(10000, 5000, 2000, $minimum, true);

    expect(PoolMath::estimateX100(10000, 5000, $largest, 2000, true))->toBeGreaterThanOrEqual($minimum)
        ->and(PoolMath::estimateX100(10000, 5000, $largest + 1, 2000, true))->toBeLessThan($minimum);
})->with([101, 105]);

it('reports no room on a side already below the minimum, and no limit at a 1x minimum', function () {
    expect(PoolMath::maxStakeForMinimum(1000, 990, 2000, 105, true))->toBe(0)
        ->and(PoolMath::maxStakeForMinimum(1000, 990, 2000, 100, true))->toBe(PHP_INT_MAX);
});

it('keeps the brief\'s numbers under the old cut on the whole pool', function () {
    // Y = 300 + 100 + 200 = 600, N = 700 + 500 = 1,200, T = 1,800, cut 13% of T
    $multiplier = PoolMath::multiplierX100(1800, 600, 1300, false);

    expect(PoolMath::multiplierX100(1000, 300, 1300, false))->toBe(290)
        ->and(PoolMath::multiplierX100(1000, 700, 1300, false))->toBe(124)
        ->and($multiplier)->toBe(261)
        ->and(PoolMath::multiplierX100(1800, 1200, 1300, false))->toBe(130)
        ->and(PoolMath::payoutMinor(100, $multiplier, PHP_INT_MAX))->toBe(261)
        ->and(PoolMath::payoutMinor(200, $multiplier, PHP_INT_MAX))->toBe(522)
        ->and(PoolMath::estimateX100(1000, 300, 500, 1300, false))->toBe(163);
});

it('keeps the old minimum-stake bound for markets on the whole-pool cut', function () {
    $largest = PoolMath::maxStakeForMinimum(10000, 5000, 1000, 105, false);

    expect(PoolMath::estimateX100(10000, 5000, $largest, 1000, false))->toBeGreaterThanOrEqual(105)
        ->and(PoolMath::estimateX100(10000, 5000, $largest + 1, 1000, false))->toBeLessThan(105)
        ->and(PoolMath::maxStakeForMinimum(1000, 900, 1000, 105, false))->toBe(0);
});

it('caps payouts at the market maximum', function () {
    expect(PoolMath::payoutMinor(1000, 500, 4000))->toBe(4000);
});
