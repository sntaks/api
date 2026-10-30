<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlayerResource;
use App\Models\ApiKey;
use App\Models\Customer;
use App\Models\Game;
use App\Services\LaunchTokenService;
use App\Services\PlayerBalance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Launching a game: kadi (site key) asks for a launch token for its logged-in player, the game (its game
 * key) redeems it for a player token. Neither route is idempotent on purpose: a stored response would
 * replay a live token.
 */
class LaunchTokenController extends Controller
{
    public function __construct(private LaunchTokenService $launchTokens, private PlayerBalance $balances) {}

    public function store(Request $request, string $encryptedIdentifier): JsonResponse
    {
        $validated = $request->validate([
            'game' => ['required', 'string', 'max:30'],
            'ip' => ['nullable', 'ip'],
        ]);

        $customer = Customer::withTrashed()->find($encryptedIdentifier);

        if (! $customer) {
            return response()->json(['success' => false, 'message' => 'Customer not found'], 404);
        }

        $game = Game::where('code', $validated['game'])->first();

        if (! $game) {
            return response()->json(['success' => false, 'message' => 'Game not found'], 404);
        }

        $launch = $this->launchTokens->issue($customer, $game, $validated['ip'] ?? null);

        return response()->json([
            'success' => true,
            'message' => 'Launch token issued. It works once, within its expiry.',
            'data' => [
                'token' => $launch['token'],
                'expires_at' => $launch['expires_at']->toIso8601String(),
                'launch_url' => $launch['launch_url'],
            ],
        ], 201);
    }

    public function redeem(Request $request): JsonResponse
    {
        /** @var ApiKey|null $apiKey */
        $apiKey = $request->attributes->get('api_key');

        if (! $apiKey?->isGameKey() || ! $apiKey->game) {
            return response()->json(['message' => 'This route needs a game API key', 'code' => 'game_key_required'], 403);
        }

        $validated = $request->validate(['token' => ['required', 'string', 'size:40']]);

        $session = $this->launchTokens->redeem($validated['token'], $apiKey);

        return response()->json([
            'success' => true,
            'message' => 'Player session started',
            'data' => [
                'player_token' => $session['player_token'],
                'expires_at' => $session['expires_at']->toIso8601String(),
                'customer' => PlayerResource::make($session['customer']),
                'balance' => $this->balances->for($session['customer']),
            ],
        ]);
    }
}
