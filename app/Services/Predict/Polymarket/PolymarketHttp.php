<?php

namespace App\Services\Predict\Polymarket;

use App\Exceptions\PolymarketUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Read-only calls to Polymarket's public APIs: short timeouts, retries with exponential backoff and jitter on
 * connection errors, 429 and 5xx, and the circuit breaker. Every failure surfaces as PolymarketUnavailable.
 */
class PolymarketHttp
{
    public function __construct(private CircuitBreaker $circuit) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array<mixed>
     */
    public function get(string $baseUrl, string $path, array $query = []): array
    {
        return $this->call($path, fn (PendingRequest $request) => $request->baseUrl($baseUrl)->get($path, $query));
    }

    /**
     * @param  array<mixed>  $body
     * @return array<mixed>
     */
    public function post(string $baseUrl, string $path, array $body): array
    {
        return $this->call($path, fn (PendingRequest $request) => $request->baseUrl($baseUrl)->post($path, $body));
    }

    /**
     * @param  callable(PendingRequest): Response  $send
     * @return array<mixed>
     */
    private function call(string $path, callable $send): array
    {
        if ($this->circuit->isOpen()) {
            throw new PolymarketUnavailable('Polymarket circuit is open');
        }

        $base = (int) config('predict.polymarket.retry_base_ms');
        // Gamma refuses large /events pages without gzip (406 gzip_required). Guzzle's default decode_content
        // hands this header to curl, which decompresses the body.
        $request = Http::acceptJson()
            ->withHeaders(['Accept-Encoding' => 'gzip'])
            ->connectTimeout((int) config('predict.polymarket.connect_timeout'))
            ->timeout((int) config('predict.polymarket.timeout'))
            ->retry(
                (int) config('predict.polymarket.retries'),
                fn (int $attempt) => $base * (2 ** ($attempt - 1)) + random_int(0, $base),
                fn (Throwable $e) => $e instanceof ConnectionException
                    || ($e instanceof RequestException && ($e->response->status() === 429 || $e->response->serverError())),
            );

        $started = microtime(true);

        try {
            $response = $send($request)->throw();
        } catch (Throwable $e) {
            $this->circuit->recordFailure();
            Log::warning('Polymarket call failed', ['path' => $path, 'error' => $e->getMessage()]);

            throw new PolymarketUnavailable("Polymarket call to {$path} failed: ".$e->getMessage(), 0, $e);
        }

        $this->circuit->recordSuccess();
        Log::debug('Polymarket call', ['path' => $path, 'ms' => (int) ((microtime(true) - $started) * 1000)]);

        return (array) $response->json();
    }
}
