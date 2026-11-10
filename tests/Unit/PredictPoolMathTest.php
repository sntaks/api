<?php

use App\Services\Predict\PoolMath;

/*
 * The brief's required pool numbers: seed 1,000, p = 0.30, cut 0.13. Amounts here are whole units (the maths is
 * unit-free); multipliers are hundredths.
 */

it('opens a 1,000 seed at p 0.30 as YES 2.90x and NO 1.24x', function () {
    $seed = PoolMath::seedSplit(1000, 3000);

    expect($seed)->toBe(['yes' => 300, 'no' => 700])
        ->and(PoolMath::multiplierX100(1000, 300, 1300))->toBe(290)
        ->and(PoolMath::multiplierX100(1000, 700, 1300))->toBe(124);
});

it('prices YES 2.61x and NO 1.30x after +100 YES, +500 NO, +200 YES', function () {
    // Y = 300 + 100 + 200 = 600, N = 700 + 500 = 1,200, T = 1,800
    expect(PoolMath::multiplierX100(1800, 600, 1300))->toBe(261)
        ->and(PoolMath::multiplierX100(1800, 1200, 1300))->toBe(130);
});

it('pays 261 and 522 when YES wins and leaves the house +17', function () {
    $multiplier = PoolMath::multiplierX100(1800, 600, 1300);
    $userPayouts = PoolMath::payoutMinor(100, $multiplier, PHP_INT_MAX) + PoolMath::payoutMinor(200, $multiplier, PHP_INT_MAX);

    expect(PoolMath::payoutMinor(100, $multiplier, PHP_INT_MAX))->toBe(261)
        ->and(PoolMath::payoutMinor(200, $multiplier, PHP_INT_MAX))->toBe(522)
        ->and(PoolMath::payoutMinor(300, $multiplier, PHP_INT_MAX))->toBe(783)
        // cut = T × c = 234; house net = user stakes 800 − user payouts 783
        ->and((int) (1800 * 0.13))->toBe(234)
        ->and(800 - $userPayouts)->toBe(17);
});

it('caps the house loss at the seed: 500 on YES against the opening pool loses 315', function () {
    $multiplier = PoolMath::multiplierX100(1500, 800, 1300);
    $userPayout = PoolMath::payoutMinor(500, $multiplier, PHP_INT_MAX);

    expect($multiplier)->toBe(163)
        ->and($userPayout)->toBe(815)
        ->and(500 - $userPayout)->toBe(-315)
        ->and(500 - $userPayout)->toBeGreaterThanOrEqual(-1000);
});

it('estimates a stake as (T + s)(1 − c) / (side + s)', function () {
    expect(PoolMath::estimateX100(1000, 300, 500, 1300))->toBe(163);
});

it('caps payouts at the market maximum', function () {
    expect(PoolMath::payoutMinor(1000, 500, 4000))->toBe(4000);
});

it('finds the largest stake that keeps a side at the minimum multiplier', function () {
    // T = 10,000, YES = 5,000, cut 10%, minimum 1.05x
    $largest = PoolMath::maxStakeForMinimum(10000, 5000, 1000, 105);

    expect(PoolMath::estimateX100(10000, 5000, $largest, 1000))->toBeGreaterThanOrEqual(105)
        ->and(PoolMath::estimateX100(10000, 5000, $largest + 1, 1000))->toBeLessThan(105);
});

it('reports no room on a side already below the minimum', function () {
    expect(PoolMath::maxStakeForMinimum(1000, 900, 1000, 105))->toBe(0);
});
