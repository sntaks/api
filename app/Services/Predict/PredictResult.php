<?php

namespace App\Services\Predict;

/**
 * What a prediction-market action did: its payload, or the refusal in the API's {code, message} shape.
 */
final readonly class PredictResult
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array{code: string, message: string, status: int, extra: array<string, mixed>}|null  $refusal
     */
    private function __construct(
        public array $data = [],
        public ?array $refusal = null,
        public bool $replayed = false,
        public bool $created = false,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function ok(array $data, bool $created = false, bool $replayed = false): self
    {
        return new self($data, null, $replayed, $created);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public static function refused(string $code, string $message, int $status, array $extra = []): self
    {
        return new self([], ['code' => $code, 'message' => $message, 'status' => $status, 'extra' => $extra]);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }
}
