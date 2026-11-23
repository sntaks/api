<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Models\CatalogStatusChange;
use App\Models\Game;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GMS: the status history of a game or a product, newest first.
 */
class CatalogStatusChangeController extends Controller
{
    public function game(Request $request, string $code): JsonResponse
    {
        return $this->history($request, CatalogStatusChange::SUBJECT_GAME, Game::idFor($code), 'Game not found');
    }

    public function product(Request $request, string $code): JsonResponse
    {
        return $this->history($request, CatalogStatusChange::SUBJECT_PRODUCT, Product::idFor($code), 'Product not found');
    }

    private function history(Request $request, string $subjectType, ?int $subjectId, string $notFound): JsonResponse
    {
        $perPage = (int) ($request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:200']])['per_page'] ?? 50);

        if ($subjectId === null) {
            return response()->json(['success' => false, 'message' => $notFound], 404);
        }

        $page = CatalogStatusChange::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        return $this->pagedResponse($page, fn (CatalogStatusChange $change) => [
            'from_status' => $change->from_status,
            'to_status' => $change->to_status,
            'reason' => $change->reason,
            'admin_user' => $change->admin_user,
            'actor' => $change->actor,
            'created_at' => $change->created_at,
        ]);
    }
}
