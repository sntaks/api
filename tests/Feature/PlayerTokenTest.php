<?php

use App\Models\ApiKey;
use App\Models\Customer;
use App\Models\Game;
use App\Services\PlayerTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    // No production route takes a player token before P3, so the middleware is probed through a test route.
    Route::middleware(['apikey.checker', 'player.token'])->get('/api/v1/_player-probe', fn (Request $request) => [
        'customer_id' => $request->attributes->get('player')->id,
    ]);
    config(['api_scopes.game' => ['GET api/v1/_player-probe']]);

    $this->crash = Game::factory()->create(['code' => 'crash']);
    $this->sportsbook = Game::factory()->create(['code' => 'sportsbook']);
    $this->crashKey = ApiKey::create(['key' => 'game_crash_key', 'name' => 'crash', 'scope' => ApiKey::SCOPE_GAME, 'game_id' => $this->crash->id, 'is_active' => true]);
    $this->player = Customer::factory()->create(['status' => 1]);
    $this->tokens = app(PlayerTokenService::class);
});

function probe(object $test, ?string $token, ?ApiKey $key = null)
{
    return $test->getJson('/api/v1/_player-probe', apiHeaders(($key ?? $test->crashKey)->key) + array_filter(['Authorization' => $token ? "Bearer {$token}" : null]));
}

it('resolves the player from a valid token', function () {
    $token = $this->tokens->issue($this->player, $this->crash);

    probe($this, $token->plainTextToken)->assertOk()->assertExactJson(['customer_id' => $this->player->id]);

    expect($token->accessToken->abilities)->toBe(['game:crash'])
        ->and($token->accessToken->name)->toBe('player:crash')
        ->and($token->accessToken->expires_at->diffInHours(now(), true))->toEqualWithDelta(12, 0.01);
});

it('refuses a missing or unknown token', function (?string $token) {
    probe($this, $token)->assertUnauthorized()->assertJsonPath('code', 'invalid_player_token');
})->with([
    'no token' => [null],
    'made up' => ['1|not-a-real-token'],
]);

it('refuses an expired token', function () {
    $token = $this->tokens->issue($this->player, $this->crash)->plainTextToken;

    $this->travel(13)->hours();

    probe($this, $token)->assertUnauthorized()->assertJsonPath('code', 'invalid_player_token');
});

it('refuses a player token issued for another game', function () {
    $token = $this->tokens->issue($this->player, $this->sportsbook)->plainTextToken;

    probe($this, $token)->assertUnauthorized()->assertJsonPath('code', 'invalid_player_token');
});

it('refuses a token with the wildcard ability', function () {
    $token = $this->player->createToken('anything')->plainTextToken;

    probe($this, $token)->assertUnauthorized()->assertJsonPath('code', 'invalid_player_token');
});

it('refuses a token of a suspended or deleted customer', function (Closure $disable) {
    $token = $this->tokens->issue($this->player, $this->crash)->plainTextToken;
    $disable($this->player);

    probe($this, $token)->assertForbidden()->assertJsonPath('code', 'player_inactive');
})->with([
    'suspended' => [fn (Customer $customer) => $customer->update(['status' => 0])],
    'deleted' => [fn (Customer $customer) => $customer->delete()],
]);

it('refuses a player token on a non-game key', function () {
    Route::middleware(['apikey.checker', 'player.token'])->get('/api/v1/_player-probe-full', fn () => ['ok' => true]);
    $token = $this->tokens->issue($this->player, $this->crash)->plainTextToken;

    $this->getJson('/api/v1/_player-probe-full', apiHeaders(createApiKey('full-probe-key')->key) + ['Authorization' => "Bearer {$token}"])
        ->assertForbidden()
        ->assertJsonPath('code', 'game_key_required');
});

it('revokes every token of a customer', function () {
    $crashToken = $this->tokens->issue($this->player, $this->crash)->plainTextToken;
    $this->tokens->issue($this->player, $this->sportsbook);
    $other = Customer::factory()->create(['status' => 1]);
    $otherToken = $this->tokens->issue($other, $this->crash)->plainTextToken;

    expect($this->tokens->revokeAll($this->player))->toBe(2);

    probe($this, $crashToken)->assertUnauthorized();
    probe($this, $otherToken)->assertOk();
});
