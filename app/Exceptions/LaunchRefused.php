<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;

/**
 * A launch refused because the player may not play or the game cannot be entered, at issue or at redeem.
 *
 * An expected refusal, not an error: never logged, so no token (or its prefix in a stack trace) reaches the log.
 */
class LaunchRefused extends Exception implements ShouldntReport
{
    public function __construct(string $message, public readonly string $errorCode)
    {
        parent::__construct($message);
    }

    public static function playerInactive(): self
    {
        return new self('This player cannot play', 'player_inactive');
    }

    public static function gameUnavailable(): self
    {
        return new self('This game cannot be launched now', 'game_unavailable');
    }

    public function render(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $this->getMessage(), 'code' => $this->errorCode], 403);
    }
}
