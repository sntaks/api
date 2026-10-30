<?php

namespace App\Services;

use App\Exceptions\InvalidLaunchToken;
use App\Exceptions\LaunchRefused;
use App\Models\ApiKey;
use App\Models\Customer;
use App\Models\Game;
use App\Models\LaunchToken;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Kadi vouches for a logged-in player by asking for a launch token, and sends the player to the game with
 * it. The game redeems it once, with its own game key, for a player token. The API never sees a password.
 */
class LaunchTokenService
{
    public function __construct(private PlayerTokenService $playerTokens) {}

    /**
     * @return array{token: string, expires_at: CarbonImmutable, launch_url: string}
     *
     * @throws LaunchRefused
     */
    public function issue(Customer $customer, Game $game, ?string $ip): array
    {
        $this->ensureCanLaunch($customer, $game);

        $token = Str::random(40);
        $expiresAt = CarbonImmutable::now()->addSeconds((int) config('player_auth.launch_token_ttl_seconds'));

        LaunchToken::create([
            'customer_id' => $customer->id,
            'game_id' => $game->id,
            'token_hash' => LaunchToken::hash($token),
            'expires_at' => $expiresAt,
            'ip' => $ip,
        ]);

        return ['token' => $token, 'expires_at' => $expiresAt, 'launch_url' => $game->launch_url];
    }

    /**
     * Uses the token up with one conditional update, so of two redeems racing for it exactly one wins. A
     * token for another game is left untouched. The player and the game are checked again, since either
     * may have been suspended since the launch; the token stays used.
     *
     * @return array{player_token: string, expires_at: CarbonImmutable, customer: Customer}
     *
     * @throws InvalidLaunchToken
     * @throws LaunchRefused
     */
    public function redeem(string $token, ApiKey $gameKey): array
    {
        $game = $gameKey->game;
        $hash = LaunchToken::hash($token);

        $claimed = LaunchToken::query()
            ->where('token_hash', $hash)
            ->where('game_id', $game->id)
            ->whereNull('redeemed_at')
            ->where('expires_at', '>', now())
            ->update(['redeemed_at' => now(), 'redeemed_by_api_key_id' => $gameKey->id]);

        if ($claimed !== 1) {
            throw new InvalidLaunchToken;
        }

        $launchToken = LaunchToken::where('token_hash', $hash)->sole();
        $customer = Customer::withTrashed()->find($launchToken->customer_id);

        if (! $customer) {
            throw LaunchRefused::playerInactive();
        }

        $this->ensureCanLaunch($customer, $game->refresh());

        $playerToken = $this->playerTokens->issue($customer, $game);

        return [
            'player_token' => $playerToken->plainTextToken,
            'expires_at' => CarbonImmutable::parse($playerToken->accessToken->expires_at),
            'customer' => $customer,
        ];
    }

    /**
     * @throws LaunchRefused
     */
    private function ensureCanLaunch(Customer $customer, Game $game): void
    {
        if ($customer->trashed() || (int) $customer->status !== 1) {
            throw LaunchRefused::playerInactive();
        }

        if ($game->status !== Game::STATUS_ACTIVE || blank($game->launch_url)) {
            throw LaunchRefused::gameUnavailable();
        }
    }
}
