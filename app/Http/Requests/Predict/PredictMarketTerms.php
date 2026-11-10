<?php

namespace App\Http\Requests\Predict;

use Illuminate\Contracts\Validation\Validator;

/**
 * Cross-field checks on a pool market's terms, with config defaults for what is not sent.
 */
class PredictMarketTerms
{
    /**
     * @param  array<string, mixed>  $terms
     */
    public static function check(Validator $validator, array $terms): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $minStake = (float) ($terms['min_stake'] ?? config('predict.limits.min_stake'));
        $maxStake = (float) ($terms['max_stake'] ?? config('predict.limits.max_stake'));

        if ($minStake > $maxStake) {
            $validator->errors()->add('max_stake', 'The max stake must be at least the min stake.');
        }

        if (! array_key_exists('start_probability', $terms)) {
            return;
        }

        // The opening odds of both sides must clear the minimum multiplier, or one side starts full
        $cut = (float) ($terms['cut'] ?? config('predict.pool.cut'));
        $minMultiplier = (float) ($terms['min_multiplier'] ?? config('predict.pool.min_multiplier'));
        $largestShare = max((float) $terms['start_probability'], 1 - (float) $terms['start_probability']);

        if ((1 - $cut) / $largestShare < $minMultiplier) {
            $validator->errors()->add('start_probability', 'With this cut, the favourite would open below the minimum multiplier.');
        }
    }
}
