<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;

/**
 * An unknown, expired, used or other-game launch token. One answer for all of them, so a game key cannot
 * use redeem to learn anything about a token.
 *
 * An expected refusal, not an error: never logged, so no token (or its prefix in a stack trace) reaches the log.
 */
class InvalidLaunchToken extends Exception implements ShouldntReport
{
    public function __construct()
    {
        parent::__construct('Invalid or expired launch token');
    }

    public function render(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $this->getMessage(), 'code' => 'invalid_launch_token'], 401);
    }
}
