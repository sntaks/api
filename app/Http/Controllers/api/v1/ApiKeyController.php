<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\Game;
use App\Services\ApiKeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GMS: every API key (never the key itself), game keys issued per game, and revoking any key.
 */
class ApiKeyController extends Controller
{
    public function __construct(private ApiKeyService $keys) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'scope' => ['nullable', 'in:'.implode(',', ApiKey::SCOPES)],
            'game' => ['nullable', 'string', 'max:30'],
            'active' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $page = ApiKey::query()
            ->with(['game', 'betShop'])
            ->when($filters['scope'] ?? null, fn ($query, string $scope) => $query->where('scope', $scope))
            ->when($filters['game'] ?? null, fn ($query, string $game) => $query->whereRelation('game', 'code', $game))
            ->when(isset($filters['active']), fn ($query) => $query->where('is_active', (bool) $filters['active']))
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 50));

        return $this->pagedResponse($page, fn (ApiKey $key) => $this->keys->present($key));
    }

    /**
     * Not idempotent on purpose: a stored response would keep the new key in the database for 24 hours.
     */
    public function issueGameKey(Request $request, string $code): JsonResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'min:3', 'max:255']]);
        $game = Game::where('code', $code)->first();

        if (! $game) {
            return response()->json(['success' => false, 'message' => 'Game not found'], 404);
        }

        if ($game->status === Game::STATUS_RETIRED) {
            return response()->json(['success' => false, 'code' => 'game_retired', 'message' => 'The game is retired'], 409);
        }

        ['api_key' => $apiKey, 'plain_key' => $plainKey] = $this->keys->issue($validated['name'], ApiKey::SCOPE_GAME, $game);

        return response()->json([
            'success' => true,
            'message' => 'Key issued. It is shown only once.',
            'data' => $this->keys->present($apiKey->load('game')) + ['key' => $plainKey],
        ], 201);
    }

    public function revoke(Request $request, string $encryptedIdentifier): JsonResponse
    {
        $apiKey = ApiKey::with(['game', 'betShop'])->find($encryptedIdentifier);

        if (! $apiKey) {
            return response()->json(['success' => false, 'message' => 'API key not found'], 404);
        }

        if ($apiKey->is($request->attributes->get('api_key'))) {
            return response()->json(['success' => false, 'code' => 'own_key', 'message' => 'A key cannot revoke itself'], 409);
        }

        return response()->json([
            'success' => true,
            'message' => 'Key revoked',
            'data' => $this->keys->present($this->keys->revoke($apiKey, $this->actorFor($request))),
        ]);
    }
}
