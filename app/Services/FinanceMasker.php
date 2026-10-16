<?php

namespace App\Services;

/**
 * Hides most of any phone number in a value, so finance reports and CSV exports can be shared
 * without exposing customer contact details.
 */
class FinanceMasker
{
    /**
     * Masks every run of 9 or more digits, keeping the first and last four: 254712345678 becomes 2547****5678.
     */
    /**
     * A name with only the first name and the first letter of the last name shown (John Kamau becomes
     * John K****), enough for a shop owner to confirm they have the right player.
     */
    public function name(?string $value): ?string
    {
        $words = preg_split('/\s+/', trim((string) $value), -1, PREG_SPLIT_NO_EMPTY);

        if ($words === []) {
            return null;
        }

        if (count($words) === 1) {
            return mb_substr($words[0], 0, 1).'****';
        }

        return $words[0].' '.mb_substr(end($words), 0, 1).'****';
    }

    public function phone(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return preg_replace_callback('/\d{9,}/', function (array $match) {
            $digits = $match[0];

            return substr($digits, 0, 4).str_repeat('*', strlen($digits) - 8).substr($digits, -4);
        }, $value);
    }
}
