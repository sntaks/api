<?php

use App\Models\ApiKey;
use App\Models\Customer;
use App\Models\Game;
use App\Models\LaunchToken;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->crash = Game::factory()->create(['code' => 'crash', 'launch_url' => 'https://crash.kadikings.co.ke/launch']);
    $this->sportsbook = Game::factory()->create(['code' => 'sportsbook']);
    $this->siteKey = ApiKey::create(['key' => 'site_test_key', 'name' => 'kadi-site', 'scope' => ApiKey::SCOPE_SITE, 'is_active' => true]);
    $this->crashKey = ApiKey::create(['key' => 'game_crash_key', 'name' => 'crash', 'scope' => ApiKey::SCOPE_GAME, 'game_id' => $this->crash->id, 'is_active' => true]);
    $this->sportsbookKey = ApiKey::create(['key' => 'game_sportsbook_key', 'name' => 'sportsbook', 'scope' => ApiKey::SCOPE_GAME, 'game_id' => $this->sportsbook->id, 'is_active' => true]);
    $this->player = Customer::factory()->create(['status' => 1]);
    Wallet::factory()->create(['customer_id' => $this->player->id, 'balance' => 250.5]);
});

function launch(object $test, Customer $customer, string $game, ?ApiKey $key = null, array $extra = [])
{
    return $test->postJson(
        '/api/v1/customers/'.encryptId($customer->id).'/launch-tokens',
        ['game' => $game] + $extra,
        apiHeaders(($key ?? $test->siteKey)->key),
    );
}

function redeem(object $test, string $token, ?ApiKey $key = null)
{
    return $test->postJson('/api/v1/launch-tokens/redeem', ['token' => $token], apiHeaders(($key ?? $test->crashKey)->key));
}

function launchToken(object $test, ?Customer $customer = null, string $game = 'crash'): string
{
    return launch($test, $customer ?? $test->player, $game)->assertCreated()->json('data.token');
}

it('issues a launch token for an active player and game', function () {
    $response = launch($this, $this->player, 'crash', extra: ['ip' => '41.90.1.2'])
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.launch_url', 'https://crash.kadikings.co.ke/launch');

    $token = $response->json('data.token');
    $row = LaunchToken::sole();

    expect($token)->toBeString()->toHaveLength(40)
        ->and($row->token_hash)->toBe(hash('sha256', $token))
        ->and($row->customer_id)->toBe($this->player->id)
        ->and($row->game_id)->toBe($this->crash->id)
        ->and($row->ip)->toBe('41.90.1.2')
        ->and($row->redeemed_at)->toBeNull()
        ->and(abs(now()->addSeconds(120)->diffInSeconds($row->expires_at)))->toBeLessThan(2)
        ->and(DB::table('launch_tokens')->where('token_hash', $token)->exists())->toBeFalse();
});

it('redeems a launch token for a player token', function () {
    $token = launchToken($this);

    $response = redeem($this, $token)
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.customer.id', $this->player->id)
        ->assertJsonPath('data.customer.player_no', $this->player->player_no)
        ->assertJsonPath('data.balance.balance', 250.5)
        ->assertJsonMissingPath('data.customer.phone_no')
        ->assertJsonMissingPath('data.customer.email');

    $playerToken = PersonalAccessToken::findToken($response->json('data.player_token'));

    expect($playerToken->tokenable_id)->toBe($this->player->id)
        ->and($playerToken->abilities)->toBe(['game:crash'])
        ->and(LaunchToken::sole()->redeemed_at)->not->toBeNull()
        ->and(LaunchToken::sole()->redeemed_by_api_key_id)->toBe($this->crashKey->id);
});

it('redeems a launch token only once', function () {
    $token = launchToken($this);

    redeem($this, $token)->assertOk();
    redeem($this, $token)->assertUnauthorized()->assertJsonPath('code', 'invalid_launch_token');

    expect(PersonalAccessToken::count())->toBe(1);
});

it('refuses an expired launch token', function () {
    $token = launchToken($this);

    $this->travel(121)->seconds();

    redeem($this, $token)->assertUnauthorized()->assertJsonPath('code', 'invalid_launch_token');
});

it('refuses a launch token for another game without using it up', function () {
    $token = launchToken($this);

    redeem($this, $token, $this->sportsbookKey)->assertUnauthorized()->assertJsonPath('code', 'invalid_launch_token');

    redeem($this, $token)->assertOk();
});

it('gives one answer for every bad token', function () {
    $used = launchToken($this);
    redeem($this, $used)->assertOk();
    $otherGame = launchToken($this);
    $expired = launchToken($this);
    LaunchToken::where('token_hash', hash('sha256', $expired))->update(['expires_at' => now()->subSecond()]);

    $bodies = collect([str_repeat('a', 40), $used, $expired])
        ->map(fn (string $token) => redeem($this, $token)->assertUnauthorized()->json())
        ->push(redeem($this, $otherGame, $this->sportsbookKey)->assertUnauthorized()->json());

    expect($bodies->unique()->count())->toBe(1);
});

it('refuses to launch an inactive player', function (Closure $disable) {
    $disable($this->player);

    launch($this, $this->player, 'crash')->assertForbidden()->assertJsonPath('code', 'player_inactive');

    expect(LaunchToken::count())->toBe(0);
})->with([
    'blocked' => [fn (Customer $customer) => $customer->update(['status' => 2])],
    'confirmed' => [fn (Customer $customer) => $customer->update(['status' => 3])],
    'deleted status' => [fn (Customer $customer) => $customer->update(['status' => 4])],
    'fraud' => [fn (Customer $customer) => $customer->update(['status' => 5])],
    'lead' => [fn (Customer $customer) => $customer->update(['status' => 6])],
    'soft deleted' => [fn (Customer $customer) => $customer->delete()],
]);

it('refuses to launch a game that is not active or has no launch url', function (array $attributes) {
    $this->crash->update($attributes);

    launch($this, $this->player, 'crash')->assertForbidden()->assertJsonPath('code', 'game_unavailable');

    expect(LaunchToken::count())->toBe(0);
})->with([
    'suspended' => [['status' => Game::STATUS_SUSPENDED]],
    'retired' => [['status' => Game::STATUS_RETIRED]],
    'no launch url' => [['launch_url' => '']],
]);

it('answers 404 for an unknown game or customer', function () {
    launch($this, $this->player, 'nope')->assertNotFound();

    $this->postJson('/api/v1/customers/'.encryptId(999999).'/launch-tokens', ['game' => 'crash'], apiHeaders($this->siteKey->key))
        ->assertNotFound();
});

it('refuses to redeem when the player was suspended after the launch', function () {
    $token = launchToken($this);
    $this->player->update(['status' => 2]);

    redeem($this, $token)->assertForbidden()->assertJsonPath('code', 'player_inactive');

    expect(PersonalAccessToken::count())->toBe(0);
});

it('refuses to redeem when the game was suspended after the launch', function () {
    $token = launchToken($this);
    $this->crash->update(['status' => Game::STATUS_SUSPENDED]);

    redeem($this, $token)->assertForbidden()->assertJsonPath('code', 'game_unavailable');

    expect(PersonalAccessToken::count())->toBe(0);
});

it('refuses redeem from a site or full key', function (Closure $key, string $code) {
    $token = launchToken($this);

    redeem($this, $token, $key($this))->assertForbidden()->assertJsonPath('code', $code);

    redeem($this, $token)->assertOk();
})->with([
    'site (fenced)' => [fn (object $test) => $test->siteKey, 'route_not_allowed'],
    'full' => [fn () => createApiKey('full-key'), 'game_key_required'],
]);

it('refuses issue from a game key', function () {
    launch($this, $this->player, 'crash', $this->crashKey)->assertForbidden()->assertJsonPath('code', 'route_not_allowed');
});

it('refuses a malformed token', function (mixed $token) {
    $this->postJson('/api/v1/launch-tokens/redeem', ['token' => $token], apiHeaders($this->crashKey->key))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('token');
})->with([
    'missing' => [null],
    'too short' => ['abc'],
    'not a string' => [[1, 2]],
]);

it('refuses a token another connection already redeemed', function () {
    $token = launchToken($this);
    DB::table('launch_tokens')->update(['redeemed_at' => now()]);

    redeem($this, $token)->assertUnauthorized()->assertJsonPath('code', 'invalid_launch_token');
});

it('prunes launch tokens older than a day', function () {
    launchToken($this);
    $this->travel(25)->hours();
    launchToken($this);

    $this->artisan('launch-tokens:prune')->assertSuccessful();

    expect(LaunchToken::count())->toBe(1);
});

it('does not write refused launches to the error log', function () {
    $token = launchToken($this);
    $this->player->update(['status' => 2]);
    Log::spy();

    redeem($this, str_repeat('a', 40))->assertUnauthorized();
    redeem($this, $token)->assertForbidden();
    launch($this, $this->player, 'crash')->assertForbidden();

    Log::shouldNotHaveReceived('error');
});

it('schedules the token prunes daily', function () {
    $this->artisan('schedule:list')
        ->expectsOutputToContain('php artisan launch-tokens:prune')
        ->expectsOutputToContain('php artisan sanctum:prune-expired --hours=24')
        ->assertSuccessful();
});
