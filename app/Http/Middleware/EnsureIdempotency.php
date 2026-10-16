<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class EnsureIdempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        $endpoint = $request->route()->getActionMethod().'@'.$request->path();
        $apiKey = $request->header('X-API-KEY') ?? '';
        $requestHash = hash('sha256', json_encode($request->except(['X-API-KEY', 'Idempotency-Key'])).$endpoint.$apiKey);

        $idempotencyKey = $request->header('Idempotency-Key');

        // Auto-generate key from request hash when client doesn't send one
        if (! $idempotencyKey) {
            $apiKey = $request->header('X-API-KEY') ?? '';
            $idempotencyKey = hash('sha256', $requestHash.$apiKey);
        }

        if (Str::length($idempotencyKey) > 64) {
            return response()->json(['message' => 'Idempotency-Key must be 64 characters or fewer'], 400);
        }

        $existing = IdempotencyKey::where('idempotency_key', $idempotencyKey)->first();

        if ($existing) {
            if ($existing->isExpired()) {
                $existing->delete();
            } else {
                if ($existing->request_hash !== $requestHash) {
                    return response()->json(['message' => 'Idempotency-Key reused with different request body'], 409);
                }

                return response()->json(json_decode($existing->response_body, true), $existing->response_status);
            }
        }

        /** @var Response $response */
        $response = $next($request);

        // A server error may be transient, so it is not replayed: a retry with the same key runs again.
        if ($response->getStatusCode() >= 500) {
            return $response;
        }

        IdempotencyKey::create([
            'idempotency_key' => $idempotencyKey,
            'user_id' => $request->user()?->id,
            'endpoint' => $endpoint,
            'request_hash' => $requestHash,
            'response_status' => $response->getStatusCode(),
            'response_body' => $response->getContent(),
            'expires_at' => now()->addHours(24),
        ]);

        return $response;
    }
}
