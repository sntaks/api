<?php

use App\Services\Predict\FixedOddsMath;

it('sells at the fair price plus the margin: 0.60 with 5% offers 0.63, paying 1.58x', function () {
    $offer = FixedOddsMath::offerPrice('0.600000', 500, '0.99');

    expect($offer)->toBe('0.630000')
        ->and(FixedOddsMath::multiplierX100($offer))->toBe(158);
});

it('caps the offer price at 0.99', function () {
    expect(FixedOddsMath::offerPrice('0.980000', 500, '0.99'))->toBe('0.990000')
        ->and(FixedOddsMath::multiplierX100('0.990000'))->toBe(101);
});

it('flags a move against the house only beyond the tolerance', function () {
    expect(FixedOddsMath::movedAgainstHouse('0.630000', '0.636300', '0.01'))->toBeFalse()
        ->and(FixedOddsMath::movedAgainstHouse('0.630000', '0.636301', '0.01'))->toBeTrue()
        ->and(FixedOddsMath::movedAgainstHouse('0.630000', '0.600000', '0.01'))->toBeFalse();
});
