<?php

namespace App\Domain\Jet;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A refused Jet command, carrying its M1 error code (UPPER_SNAKE), HTTP status and retry advice
 * (reconcile, backoff or correct_request). Thrown inside JetLock, so it rolls the whole command back. A refusal
 * is an expected answer, not an error: it is never written to the error log.
 */
class JetException extends RuntimeException implements ShouldntReport
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(public readonly string $errorCode, public readonly int $status, public readonly string $retry = 'correct_request', public readonly array $extra = [])
    {
        parent::__construct($errorCode);
    }

    public function render(): JsonResponse
    {
        return response()->json(['error' => ['code' => $this->errorCode, 'retry' => $this->retry, ...$this->extra]], $this->status);
    }
}
