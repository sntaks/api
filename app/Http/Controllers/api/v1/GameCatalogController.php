<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateGameRequest;
use App\Http\Resources\GameResource;
use App\Models\Game;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The games on the platform: kadi's lobby lists the active ones, GMS manages them.
 */
class GameCatalogController extends Controller
{
    /**
     * Active games by default, in lobby order, each with its products. ?status=all lists every game.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $status = $request->validate([
            'status' => ['nullable', 'in:all,'.implode(',', Game::STATUSES)],
        ])['status'] ?? Game::STATUS_ACTIVE;

        $games = Game::query()
            ->with(['products' => fn ($query) => $query->orderBy('id')->with('game')])
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return GameResource::collection($games);
    }

    public function update(UpdateGameRequest $request, string $code): JsonResponse
    {
        $game = Game::where('code', $code)->first();

        if (! $game) {
            return response()->json(['success' => false, 'message' => 'Game not found'], 404);
        }

        $game->update($request->validated());

        return response()->json(['success' => true, 'message' => 'Game updated', 'data' => GameResource::make($game->load('products.game'))]);
    }
}
