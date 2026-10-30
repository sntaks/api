<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlayerResource;
use App\Models\Customer;
use App\Models\Game;
use App\Services\PlayerBalance;
use App\Services\PlayerTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Player sessions in games: what a game server may read about the player whose token it holds (game key
 * + player token), and kadi ending those sessions (site key).
 */
class PlayerSessionController extends Controller
{
    public function __construct(private PlayerBalance $balances, private PlayerTokenService $playerTokens) {}

    public function me(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => PlayerResource::make($request->attributes->get('player'))]);
    }

    public function balance(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->balances->for($request->attributes->get('player'))]);
    }

    /**
     * Kadi ends a player's game sessions (site key), on logout: every game, or one game when `game` is sent.
     * Repeating it is harmless: it revokes 0.
     */
    public function revoke(Request $request, string $encryptedIdentifier): JsonResponse
    {
        $validated = $request->validate(['game' => ['nullable', 'string', 'max:30']]);

        $customer = Customer::withTrashed()->find($encryptedIdentifier);

        if (! $customer) {
            return response()->json(['success' => false, 'message' => 'Customer not found'], 404);
        }

        $game = null;

        if (isset($validated['game'])) {
            $game = Game::where('code', $validated['game'])->first();

            if (! $game) {
                return response()->json(['success' => false, 'message' => 'Game not found'], 404);
            }
        }

        $revoked = $this->playerTokens->revokeAll($customer, $game);

        return response()->json(['success' => true, 'message' => 'Player sessions ended', 'data' => ['revoked' => $revoked]]);
    }
}
