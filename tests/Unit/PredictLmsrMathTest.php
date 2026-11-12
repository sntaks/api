<?php

use App\Services\Predict\LmsrMath;

it('computes exp and ln to many places', function () {
    expect(substr(LmsrMath::exp('1'), 0, 22))->toBe('2.71828182845904523536')
        ->and(substr(LmsrMath::exp('-2.5'), 0, 18))->toBe('0.0820849986238987')
        ->and(substr(LmsrMath::ln('2'), 0, 22))->toBe('0.69314718055994530941')
        ->and(substr(LmsrMath::ln('0.3'), 0, 19))->toBe('-1.2039728043259359')
        // Round trip within 1e-20
        ->and(bccomp(ltrim(bcsub(LmsrMath::ln(LmsrMath::exp('7.25')), '7.25', 30), '-'), '0.00000000000000000001', 30))->toBe(-1);
});

it('opens at the admin\'s price', function () {
    $q = LmsrMath::opening('0.30', '5000');

    expect(LmsrMath::price($q['yes'], $q['no'], '5000'))->toBe('0.300000')
        ->and(LmsrMath::price($q['no'], $q['yes'], '5000'))->toBe('0.700000');
});

it('sells shares that cost exactly the net spend', function () {
    // 50/50 at b = 5,000: KES 900 net buys b·ln(2·e^{0.18} − 1) ≈ 1,662.45 shares
    $shares = LmsrMath::sharesFor('900', '0', '0', '5000');
    $spent = bcsub(LmsrMath::cost($shares, '0', '5000'), LmsrMath::cost('0', '0', '5000'), 10);

    expect($shares)->toBe('1662.445594')
        // Shares are rounded down to 6 places, so the cost is at most a millionth under the spend (house side)
        ->and(bccomp(bcsub('900', $spent, 10), '0', 10))->toBeGreaterThanOrEqual(0)
        ->and(bccomp(bcsub('900', $spent, 10), '0.000001', 10))->toBe(-1)
        ->and(LmsrMath::price($shares, '0', '5000'))->toBe('0.582365');
});

it('makes the next share dearer after each purchase', function () {
    $first = LmsrMath::sharesFor('900', '0', '0', '5000');
    $second = LmsrMath::sharesFor('900', $first, '0', '5000');

    expect(bccomp($second, $first, 6))->toBe(-1);
});

it('bounds the house loss by b·ln(1 / the smaller opening price)', function () {
    expect(LmsrMath::worstCaseLoss('0.50', '5000'))->toBe('3465.73')
        ->and(LmsrMath::worstCaseLoss('0.30', '5000'))->toBe('6019.86');
});
