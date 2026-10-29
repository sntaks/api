<?php

use App\Models\ApiKey;
use App\Models\Game;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->gmsKey = createApiKey('test-gms-key');
    $this->headers = apiHeaders($this->gmsKey->key);
    $this->crash = Game::factory()->create(['code' => 'crash', 'name' => 'Crash']);
});

function revokeKey(object $test, ApiKey $key)
{
    return $test->postJson('/api/v1/api-keys/'.encryptId($key->id).'/revoke', [], $test->headers + ['Idempotency-Key' => (string) Str::uuid()]);
}

it('issues and revokes a game key through the API', function () {
    $issued = $this->postJson('/api/v1/games/crash/keys', ['name' => 'crash-server'], $this->headers)
        ->assertCreated()
        ->assertJsonPath('data.scope', 'game')
        ->assertJsonPath('data.game', 'crash')
        ->assertJsonPath('data.is_active', true)
        ->json('data');

    expect($issued['key'])->toStartWith('game_');
    $key = ApiKey::findOrFail($issued['id']);

    revokeKey($this, $key)
        ->assertOk()
        ->assertJsonPath('data.is_active', false)
        ->assertJsonPath('data.revoked_by', 'api_key:'.$this->gmsKey->id);

    $this->getJson('/api/v1/games', apiHeaders($issued['key']))->assertUnauthorized();
});

it('lists keys by scope and game', function () {
    $other = Game::factory()->create(['code' => 'sportsbook']);
    ApiKey::create(['key' => 'game_crash_1', 'name' => 'crash 1', 'scope' => ApiKey::SCOPE_GAME, 'game_id' => $this->crash->id, 'is_active' => true]);
    ApiKey::create(['key' => 'game_sb_1', 'name' => 'sb 1', 'scope' => ApiKey::SCOPE_GAME, 'game_id' => $other->id, 'is_active' => true]);
    ApiKey::create(['key' => 'site_1', 'name' => 'kadi site', 'scope' => ApiKey::SCOPE_SITE, 'is_active' => false]);

    $this->getJson('/api/v1/api-keys', $this->headers)->assertOk()->assertJsonPath('data.pagination.total', 4);
    $this->getJson('/api/v1/api-keys?scope=game&game=crash', $this->headers)
        ->assertOk()
        ->assertJsonPath('data.pagination.total', 1)
        ->assertJsonPath('data.items.0.name', 'crash 1');
    $this->getJson('/api/v1/api-keys?active=0', $this->headers)->assertOk()->assertJsonPath('data.items.0.name', 'kadi site');
    $this->getJson('/api/v1/api-keys?scope=root', $this->headers)->assertUnprocessable();
});

it('never shows a key after it is issued', function () {
    $id = $this->postJson('/api/v1/games/crash/keys', ['name' => 'crash-server'], $this->headers)->assertCreated()->json('data.id');

    $listed = $this->getJson('/api/v1/api-keys', $this->headers)->assertOk()->json('data.items');
    $revoked = revokeKey($this, ApiKey::findOrFail($id))->assertOk()->json('data');

    expect(collect($listed)->every(fn (array $item) => ! array_key_exists('key', $item)))->toBeTrue()
        ->and($revoked)->not->toHaveKey('key');
});

it('refuses a key for a retired or unknown game', function () {
    $this->crash->update(['status' => Game::STATUS_RETIRED]);

    $this->postJson('/api/v1/games/crash/keys', ['name' => 'crash-server'], $this->headers)
        ->assertStatus(409)
        ->assertJsonPath('code', 'game_retired');
    $this->postJson('/api/v1/games/nope/keys', ['name' => 'nope-server'], $this->headers)->assertNotFound();
    $this->postJson('/api/v1/games/kadi/keys', ['name' => 'x'], $this->headers)->assertUnprocessable();

    expect(ApiKey::where('scope', ApiKey::SCOPE_GAME)->exists())->toBeFalse();
});

it('refuses to revoke the calling key', function () {
    revokeKey($this, $this->gmsKey)->assertStatus(409)->assertJsonPath('code', 'own_key');

    expect($this->gmsKey->fresh()->is_active)->toBeTrue();
});

it('revokes a key only once and keeps who revoked it first', function () {
    $key = ApiKey::create(['key' => 'old-full-key', 'name' => 'old kadi', 'is_active' => true]);
    $this->artisan('apikey:revoke', ['key' => (string) $key->id])->assertSuccessful();
    $revokedAt = $key->fresh()->revoked_at;

    $this->travel(5)->minutes();
    revokeKey($this, $key)->assertOk()->assertJsonPath('data.revoked_by', 'console');

    expect($key->fresh()->revoked_at->equalTo($revokedAt))->toBeTrue();
});

it('revokes a shop key too', function () {
    $key = ApiKey::create(['key' => 'shop_x', 'name' => 'desk', 'scope' => ApiKey::SCOPE_SHOP, 'is_active' => true]);

    revokeKey($this, $key)->assertOk()->assertJsonPath('data.scope', 'shop');

    expect($key->fresh()->is_active)->toBeFalse();
});

it('answers 404 for an unknown key', function () {
    $this->postJson('/api/v1/api-keys/'.encryptId(999999).'/revoke', [], $this->headers)->assertNotFound();
});
