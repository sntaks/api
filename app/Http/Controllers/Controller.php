<?php

namespace App\Http\Controllers;

use App\Models\ApiKey;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * Identify the API key behind a request, for audit trails.
     */
    protected function actorFor(Request $request): ?string
    {
        $key = $request->header('X-API-KEY');
        $id = $key ? ApiKey::where('key', $key)->value('id') : null;

        return $id ? "api_key:{$id}" : null;
    }

    /**
     * One page of a list, as `{success, data: {items, pagination}}`.
     *
     * @param  callable(mixed): array<string, mixed>  $present
     */
    protected function pagedResponse(LengthAwarePaginator $page, callable $present): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'items' => collect($page->items())->map($present)->values()->all(),
                'pagination' => [
                    'page' => $page->currentPage(),
                    'per_page' => $page->perPage(),
                    'total' => $page->total(),
                    'last_page' => $page->lastPage(),
                ],
            ],
        ]);
    }
}
