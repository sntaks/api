<?php

use App\Util\PlayerNumber;

/**
 * A known-good number: body 1234567, whose Damm check digit is 1.
 */
const VALID_PLAYER_NO = '12345671';

it('appends the Damm check digit', function () {
    expect(PlayerNumber::checkDigit('1234567'))->toBe(1)
        ->and(PlayerNumber::checkDigit('572'))->toBe(4)
        ->and(PlayerNumber::checkDigit(VALID_PLAYER_NO))->toBe(0);
});

it('accepts a valid number typed with spaces or dashes', function (string $typed) {
    expect(PlayerNumber::normalize($typed))->toBe(VALID_PLAYER_NO);
})->with(['12345671', '1234 5671', ' 1234-5671 ']);

it('rejects every single-digit typo', function () {
    foreach (range(0, 7) as $position) {
        foreach (range(0, 9) as $digit) {
            if ((int) VALID_PLAYER_NO[$position] === $digit) {
                continue;
            }

            $typo = substr_replace(VALID_PLAYER_NO, (string) $digit, $position, 1);

            expect(PlayerNumber::normalize($typo))->toBeNull("{$typo} was accepted");
        }
    }
});

it('rejects every swap of two neighbouring digits', function () {
    foreach (range(0, 6) as $position) {
        $swapped = VALID_PLAYER_NO;
        [$swapped[$position], $swapped[$position + 1]] = [$swapped[$position + 1], $swapped[$position]];

        if ($swapped !== VALID_PLAYER_NO) {
            expect(PlayerNumber::normalize($swapped))->toBeNull("{$swapped} was accepted");
        }
    }
});

it('rejects anything that is not 8 digits starting with 1 to 9', function (?string $typed) {
    expect(PlayerNumber::normalize($typed))->toBeNull();
})->with([null, '', '1234567', '123456710', '0234567x', 'abcdefgh', '02345678']);

it('formats a number for display', function () {
    expect(PlayerNumber::format(VALID_PLAYER_NO))->toBe('1234 5671')
        ->and(PlayerNumber::format(null))->toBeNull();
});
