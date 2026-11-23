<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The M1 response contract for /api/v1/jet: every error is {error: {code, request_id, retry}} (no message or trace),
 * every response carries X-Request-ID and Cache-Control: private, no-store. Only applied to the Jet routes.
 */
class JetV1Response
{
    private const RECONCILE = ['ALREADY_SETTLED', 'OPERATION_EXPIRED', 'OPERATION_NOT_FOUND', 'ROUND_CHANGED', 'ROUND_CLOSED'];

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $requestId = (string) Str::uuid();
        $status = $response->getStatusCode();

        if ($status >= 400) {
            $old = json_decode((string) $response->getContent(), true) ?? [];
            $code = $old['error']['code'] ?? match ($status) {
                401 => 'UNAUTHENTICATED',
                403 => 'FORBIDDEN',
                404 => 'NOT_FOUND',
                422 => 'VALIDATION_FAILED',
                429 => 'RATE_LIMITED',
                default => 'SERVER_ERROR',
            };
            $retry = $old['error']['retry'] ?? match (true) {
                $status >= 500, in_array($code, self::RECONCILE, true) => 'reconcile',
                $status === 429 => 'backoff',
                default => 'correct_request',
            };
            $extra = array_diff_key($old['error'] ?? [], ['code' => 1, 'retry' => 1]);

            $response->setContent(json_encode(['error' => ['code' => $code, 'request_id' => $requestId, 'retry' => $retry, ...$extra]], JSON_THROW_ON_ERROR));
            $response->headers->set('Content-Type', 'application/json');
        }

        $response->headers->set('X-Request-ID', $requestId);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
