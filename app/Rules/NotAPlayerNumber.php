<?php

namespace App\Rules;

use App\Util\PlayerNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Refuses a value that reads as a player number. Deposits match the account number first, so an account
 * number equal to someone's player number would take their top-ups.
 */
class NotAPlayerNumber implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && PlayerNumber::normalize($value) !== null) {
            $fail('The :attribute cannot be a player number.');
        }
    }
}
