<?php

use App\Models\ApiKey;
use App\Models\Game;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->crash = Game::factory()->create(['code' => 'crash', 'name' => 'Crash']);
});

it('issues a game key bound to a game', function () {
    $this->artisan('apiKey:generate', ['name' => 'crash-server', '--scope' => 'game', '--game' => 'crash'])
        ->expectsOutputToContain('game_')
        ->assertSuccessful();

    $key = ApiKey::where('name', 'crash-server')->sole();

    expect($key->scope)->toBe(ApiKey::SCOPE_GAME)
        ->and($key->isGameKey())->toBeTrue()
        ->and($key->game->code)->toBe('crash')
        ->and($key->key)->toStartWith('game_')
        ->and(strlen($key->key))->toBe(45);
});

it('issues a site key', function () {
    $this->artisan('apiKey:generate', ['name' => 'kadi-site', '--scope' => 'site'])->assertSuccessful();

    $key = ApiKey::where('name', 'kadi-site')->sole();

    expect($key->scope)->toBe(ApiKey::SCOPE_SITE)
        ->and($key->isSiteKey())->toBeTrue()
        ->and($key->game_id)->toBeNull()
        ->and($key->key)->toStartWith('site_');
});

it('still issues a full key by default', function () {
    $this->artisan('apiKey:generate', ['name' => 'gms'])->assertSuccessful();

    $key = ApiKey::where('name', 'gms')->sole();

    expect($key->scope)->toBe(ApiKey::SCOPE_FULL)
        ->and(strlen($key->key))->toBe(32)
        ->and($key->isSiteKey() || $key->isGameKey() || $key->isShopKey())->toBeFalse();
});

it('refuses bad key options', function (array $options) {
    $this->artisan('apiKey:generate', ['name' => 'bad'] + $options)->assertFailed();

    expect(ApiKey::where('name', 'bad')->exists())->toBeFalse();
})->with([
    'game key without a game' => [['--scope' => 'game']],
    'unknown game' => [['--scope' => 'game', '--game' => 'nope']],
    'game on a site key' => [['--scope' => 'site', '--game' => 'crash']],
    'game on a full key' => [['--game' => 'crash']],
    'shop keys come from the bet shop routes' => [['--scope' => 'shop']],
    'unknown scope' => [['--scope' => 'root']],
]);

it('revokes a full key and the key stops working', function () {
    $key = createApiKey('gms-key-to-revoke');
    $this->getJson('/api/v1/games', apiHeaders($key->key))->assertOk();

    $this->artisan('apikey:revoke', ['key' => (string) $key->id])->assertSuccessful();

    expect($key->fresh())->is_active->toBeFalse()->revoked_by->toBe('console')
        ->and($key->fresh()->revoked_at)->not->toBeNull();
    $this->getJson('/api/v1/games', apiHeaders($key->key))->assertUnauthorized();
});

it('refuses an ambiguous key name', function () {
    ApiKey::create(['key' => 'twin-1', 'name' => 'twin', 'is_active' => true]);
    ApiKey::create(['key' => 'twin-2', 'name' => 'twin', 'is_active' => true]);

    $this->artisan('apikey:revoke', ['key' => 'twin'])->assertFailed();

    expect(ApiKey::where('name', 'twin')->where('is_active', true)->count())->toBe(2);
});
