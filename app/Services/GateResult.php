<?php

namespace App\Services;

use Illuminate\Http\JsonResponse;

/**
 * Whether a player may do something, and if not, the error the API answers with.
 */
final readonly class GateResult
{
    /**
     * @param  array<string, mixed>  $extra  Fields added to the error body, e.g. remaining or excluded_until
     */
    private function __construct(
        public bool $allowed,
        public ?string $code = null,
        public ?string $message = null,
        public int $statusCode = 200,
        public array $extra = [],
    ) {}

    public static function allow(): self
    {
        return new self(true);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public static function refuse(string $code, string $message, int $statusCode, array $extra = []): self
    {
        return new self(false, $code, $message, $statusCode, $extra);
    }

    public function toResponse(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $this->message, 'code' => $this->code] + $this->extra, $this->statusCode);
    }
}
