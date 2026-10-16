<?php

namespace App\Util;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The 8-digit number a shop owner types to find a player: 7 random digits and a Damm check digit,
 * shown as `1234 5678`. The check digit catches every single-digit typo and every swap of two
 * neighbouring digits, so a typo is rejected instead of loading the wrong player. Random rather than
 * sequential, so numbers cannot be walked and do not reveal how many customers there are.
 */
final class PlayerNumber
{
    /** The Damm algorithm's quasigroup table. */
    private const TABLE = [
        [0, 3, 1, 7, 5, 9, 8, 6, 4, 2],
        [7, 0, 9, 2, 1, 5, 4, 8, 6, 3],
        [4, 2, 0, 6, 8, 7, 1, 3, 5, 9],
        [1, 7, 5, 0, 9, 8, 3, 4, 2, 6],
        [6, 1, 2, 3, 0, 4, 5, 9, 7, 8],
        [3, 6, 7, 4, 2, 0, 9, 5, 8, 1],
        [5, 8, 6, 9, 7, 2, 0, 1, 3, 4],
        [8, 9, 4, 5, 3, 6, 2, 0, 1, 7],
        [9, 4, 3, 8, 6, 1, 7, 2, 0, 5],
        [2, 5, 8, 1, 4, 3, 6, 7, 9, 0],
    ];

    private const ATTEMPTS = 20;

    /**
     * A new number no customer has. The first digit is never 0, so the number survives being typed
     * into a spreadsheet.
     */
    public static function generateUnique(): string
    {
        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            $body = (string) random_int(1_000_000, 9_999_999);
            $number = $body.self::checkDigit($body);

            if (! DB::table('customers')->where('player_no', $number)->exists()) {
                return $number;
            }
        }

        throw new RuntimeException('Could not find a free player number.');
    }

    /**
     * The number as typed (spaces and dashes allowed), reduced to its 8 digits, or null when it is not a
     * valid player number.
     */
    public static function normalize(?string $input): ?string
    {
        $digits = preg_replace('/[\s-]/', '', (string) $input);

        return preg_match('/^[1-9]\d{7}$/', $digits) === 1 && self::checkDigit($digits) === 0 ? $digits : null;
    }

    public static function format(?string $number): ?string
    {
        return $number === null ? null : substr($number, 0, 4).' '.substr($number, 4);
    }

    /**
     * The Damm interim digit of a string of digits: the check digit to append to a body, or 0 for a
     * complete valid number.
     */
    public static function checkDigit(string $digits): int
    {
        $interim = 0;

        foreach (str_split($digits) as $digit) {
            $interim = self::TABLE[$interim][(int) $digit];
        }

        return $interim;
    }
}
