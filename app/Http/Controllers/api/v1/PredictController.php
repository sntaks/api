<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Services\Predict\PredictResult;
use Illuminate\Http\JsonResponse;

/**
 * Shared answer shape for the prediction market controllers: {success, data} or
 * {success: false, message, code, retryable, ...extra}.
 */
abstract class PredictController extends Controller
{
    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $present
     */
    protected function respond(PredictResult $result, callable $present): JsonResponse
    {
        if ($result->isRefused()) {
            return $this->refusal($result->refusal['code'], $result->refusal['message'], $result->refusal['status'], $result->refusal['extra']);
        }

        return response()->json(['success' => true, 'data' => $present($result->data) + ['replayed' => $result->replayed]], $result->created ? 201 : 200);
    }

    /**
     * A route id as an integer, or null when it is not one (ids are checked here, not by route constraints, so
     * every request reaches the API key fence first).
     */
    protected function routeId(string $id): ?int
    {
        return ctype_digit($id) ? (int) $id : null;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    protected function refusal(string $code, string $message, int $status, array $extra = []): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'code' => $code,
            'retryable' => $status >= 500 || $status === 429,
        ] + $extra, $status);
    }
}
