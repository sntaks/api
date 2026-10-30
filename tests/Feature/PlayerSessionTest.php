<?php

use App\Models\ApiKey;
use App\Models\Customer;
use App\Models\Game;
use App\Models\PromotionCredit;
use App\Models\User;
use App\Models\Wallet;
use App\Services\PlayerTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->crash = Game::factory()->create(['code' => 'crash']);
    $this->sportsbook = Game::factory()->create(['code' => 'sportsbook']);
    $this->crashKey = ApiKey::create(['key' => 'game_crash_key', 'name' => 'crash', 'scope' => ApiKey::SCOPE_GAME, 'game_id' => $this->crash->id, 'is_active' => true]);
    $this->player = Customer::factory()->create(['status' => 1, 'name' => 'jane wanjiru kamau', 'phone_no' => '254712345678', 'email' => 'jane@example.com']);
    $this->tokens = app(PlayerTokenService::class);
});

function asPlayer(object $test, string $uri, ?string $token)
{
    return $test->getJson($uri, apiHeaders($test->crashKey->key) + array_filter(['Authorization' => $token ? "Bearer {$token}" : null]));
}

function crashToken(object $test, ?Customer $customer = null): string
{
    return $test->tokens->issue($customer ?? $test->player, $test->crash)->plainTextToken;
}

it('returns the player behind the token', function () {
    asPlayer($this, '/api/v1/me', crashToken($this))
        ->assertOk()
        ->assertExactJson(['success' => true, 'data' => [
            'id' => $this->player->id,
            'player_no' => $this->player->player_no,
            'account_no' => $this->player->account_no,
            'display_name' => 'Jane K.',
            'status' => 1,
        ]]);
});

it('shows only a first name and last initial', function (string $name, string $expected) {
    $this->player->update(['name' => $name]);
    $expected = str_replace('{last4}', substr($this->player->player_no, -4), $expected);

    asPlayer($this, '/api/v1/me', crashToken($this))->assertOk()->assertJsonPath('data.display_name', $expected);
})->with([
    'one word' => ['JANE', 'Jane'],
    'extra spaces' => ['  jane   doe ', 'Jane D.'],
    'empty name' => ['', 'Player {last4}'],
    'blank name' => ['   ', 'Player {last4}'],
    'an email as the name' => ['jane.wanjiru@example.com', 'Player {last4}'],
    'a phone as the name' => ['0712345678', 'Player {last4}'],
    'digits in a two-word name' => ['Jane 0712345678', 'Player {last4}'],
]);

it('returns the player\'s balance and wallet version', function () {
    Wallet::factory()->create(['customer_id' => $this->player->id, 'balance' => 812.4, 'wallet_version' => 9]);

    asPlayer($this, '/api/v1/me/balance', crashToken($this))
        ->assertOk()
        ->assertExactJson(['success' => true, 'data' => ['balance' => 812.4, 'locked_bonus' => 0, 'wallet_version' => 9]]);
});

it('reports the locked signup bonus separately', function () {
    $wallet = Wallet::factory()->create(['customer_id' => $this->player->id, 'balance' => 20]);
    PromotionCredit::factory()->create(['customer_id' => $this->player->id, 'wallet_id' => $wallet->id]);

    asPlayer($this, '/api/v1/me/balance', crashToken($this))
        ->assertOk()
        ->assertJsonPath('data.balance', 20)
        ->assertJsonPath('data.locked_bonus', 20);
});

it('returns zeros for a player without a wallet', function () {
    asPlayer($this, '/api/v1/me/balance', crashToken($this))
        ->assertOk()
        ->assertJsonPath('data', ['balance' => 0, 'locked_bonus' => 0, 'wallet_version' => 0]);
});

it('refuses the player routes without a valid token for this game', function (string $uri, Closure $token) {
    asPlayer($this, $uri, $token($this))->assertUnauthorized()->assertJsonPath('code', 'invalid_player_token');
})->with(['/api/v1/me', '/api/v1/me/balance'])->with([
    'no token' => [fn () => null],
    'another game' => [fn (object $test) => $test->tokens->issue($test->player, $test->sportsbook)->plainTextToken],
]);

it('refuses a player token without an expiry', function () {
    $token = $this->player->createToken('player:crash', ['game:crash'])->plainTextToken;

    asPlayer($this, '/api/v1/me', $token)->assertUnauthorized()->assertJsonPath('code', 'invalid_player_token');
});

it('refuses a token owned by something other than a customer', function () {
    // Same id as the player, so only the owner type tells them apart.
    $token = PersonalAccessToken::create([
        'tokenable_type' => User::class,
        'tokenable_id' => $this->player->id,
        'name' => 'player:crash',
        'token' => hash('sha256', $plain = 'not-a-customer-token'),
        'abilities' => ['game:crash'],
        'expires_at' => now()->addHour(),
    ]);

    asPlayer($this, '/api/v1/me', "{$token->id}|{$plain}")->assertUnauthorized()->assertJsonPath('code', 'invalid_player_token');
});

it('keeps the site key off the game routes', function () {
    $site = ApiKey::create(['key' => 'site_test_key', 'name' => 'kadi-site', 'scope' => ApiKey::SCOPE_SITE, 'is_active' => true]);

    $this->getJson('/api/v1/me', apiHeaders($site->key) + ['Authorization' => 'Bearer '.crashToken($this)])
        ->assertForbidden()
        ->assertJsonPath('code', 'route_not_allowed');
});

function revokeTokens(object $test, Customer $customer, array $body = [])
{
    $site = ApiKey::firstOrCreate(['key' => 'site_test_key'], ['name' => 'kadi-site', 'scope' => ApiKey::SCOPE_SITE, 'is_active' => true]);

    return $test->postJson('/api/v1/customers/'.encryptId($customer->id).'/player-tokens/revoke', $body, apiHeaders($site->key));
}

it('revokes every player token of a customer', function () {
    $crashToken = crashToken($this);
    $this->tokens->issue($this->player, $this->sportsbook);
    $other = Customer::factory()->create(['status' => 1]);
    $otherToken = crashToken($this, $other);

    revokeTokens($this, $this->player)->assertOk()->assertJsonPath('data.revoked', 2);

    asPlayer($this, '/api/v1/me', $crashToken)->assertUnauthorized();
    asPlayer($this, '/api/v1/me', $otherToken)->assertOk();
    revokeTokens($this, $this->player)->assertOk()->assertJsonPath('data.revoked', 0);
});

it('revokes one game\'s tokens', function () {
    $crashToken = crashToken($this);
    $this->tokens->issue($this->player, $this->sportsbook);

    revokeTokens($this, $this->player, ['game' => 'sportsbook'])->assertOk()->assertJsonPath('data.revoked', 1);

    asPlayer($this, '/api/v1/me', $crashToken)->assertOk();
    expect($this->player->tokens()->count())->toBe(1);
});

it('refuses to revoke for an unknown game or customer', function () {
    revokeTokens($this, $this->player, ['game' => 'nope'])->assertNotFound();
    revokeTokens($this, (new Customer)->forceFill(['id' => 999999]))->assertNotFound();
});

it('ends game sessions when GMS suspends the player', function () {
    $token = crashToken($this);

    $this->putJson('/api/v1/customers/'.encryptId($this->player->id), ['status' => 2], apiHeaders(createApiKey('gms-key')->key))->assertOk();
    $this->player->update(['status' => 1]);

    asPlayer($this, '/api/v1/me', $token)->assertUnauthorized()->assertJsonPath('code', 'invalid_player_token');
    expect($this->player->tokens()->count())->toBe(0);
});

it('keeps game sessions when other customer fields change', function () {
    $token = crashToken($this);

    $this->player->update(['name' => 'Jane Other', 'status' => 1]);

    asPlayer($this, '/api/v1/me', $token)->assertOk();
});

it('ends game sessions when the customer is deleted', function () {
    crashToken($this);

    $this->player->delete();

    expect($this->player->tokens()->count())->toBe(0);
});

it('no longer serves the old user stub', function () {
    $this->getJson('/api/v1/user', apiHeaders(createApiKey('full-key')->key) + ['Authorization' => 'Bearer '.crashToken($this)])
        ->assertNotFound();
});
