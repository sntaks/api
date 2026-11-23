<?php

namespace App\Domain\Jet;

use RuntimeException;

/**
 * A refused Jet command, carrying its M1 error code (UPPER_SNAKE), HTTP status and retry advice
 * (reconcile, backoff or correct_request). Thrown inside JetLock, so it rolls the whole command back.
 */
class JetException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(public readonly string $errorCode, public readonly int $status, public readonly string $retry = 'correct_request', public readonly array $extra = [])
    {
        parent::__construct($errorCode);
    }
}
