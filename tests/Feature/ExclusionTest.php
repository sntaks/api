<?php

use App\Models\ApiKey;
use App\Models\Customer;
use App\Models\CustomerExclusion;
use App\Models\Game;
use App\Services\PlayerGate;
use App\Services\PlayerTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->site = ApiKey::create(['key' => 'site_test_key', 'name' => 'kadi-site', 'scope' => ApiKey::SCOPE_SITE, 'is_active' => true]);
    $this->player = Customer::factory()->create(['status' => 1]);
    $this->gate = app(PlayerGate::class);
});

function takeBreak(object $test, string $type, string $duration, ?ApiKey $key = null)
{
    return $test->postJson('/api/v1/customers/'.encryptId($test->player->id).'/exclusion', ['type' => $type, 'duration' => $duration], apiHeaders(($key ?? $test->site)->key));
}

it('starts a cool-off and refuses play until it ends', function () {
    $this->freezeTime();
    takeBreak($this, 'cool_off', '7d')
        ->assertCreated()
        ->assertJsonPath('data.type', 'cool_off')
        ->assertJsonPath('data.ends_at', now()->addDays(7)->toIso8601String());

    $result = $this->gate->canPlay($this->player->fresh());
    expect($result->allowed)->toBeFalse()
        ->and($result->code)->toBe('player_excluded')
        ->and($result->statusCode)->toBe(403)
        ->and($result->extra['excluded_until'])->toBe(now()->addDays(7)->toIso8601String());

    $row = CustomerExclusion::sole();
    expect($row->duration)->toBe('7d')->and($row->actor)->toBe("api_key:{$this->site->id}");
});

it('still lets an excluded player withdraw', function () {
    takeBreak($this, 'self_exclusion', '6m')->assertCreated();

    expect($this->gate->canWithdraw($this->player->fresh())->allowed)->toBeTrue();
});

it('lets the player play again when the break ends', function () {
    takeBreak($this, 'cool_off', '24h')->assertCreated();

    $this->travel(24)->hours();
    $this->travel(1)->seconds();

    expect($this->gate->canPlay($this->player->fresh())->allowed)->toBeTrue();
});

it('extends a break but never shortens it', function () {
    $this->freezeTime();
    takeBreak($this, 'cool_off', '30d')->assertCreated();

    takeBreak($this, 'cool_off', '7d')
        ->assertUnprocessable()
        ->assertJsonPath('code', 'exclusion_shorter')
        ->assertJsonPath('excluded_until', now()->addDays(30)->toIso8601String());

    takeBreak($this, 'self_exclusion', '6m')->assertCreated();

    expect(CustomerExclusion::count())->toBe(2)
        ->and($this->player->fresh()->activeExclusion()->type)->toBe('self_exclusion');
});

it('refuses a break that ends at the same moment as the running one', function () {
    $this->freezeTime();
    takeBreak($this, 'cool_off', '7d')->assertCreated();

    takeBreak($this, 'cool_off', '7d')->assertUnprocessable()->assertJsonPath('code', 'exclusion_shorter');
});

it('keeps a self-exclusion when a longer cool-off is asked for', function () {
    takeBreak($this, 'self_exclusion', '6m')->assertCreated();
    $this->travel(6)->months();
    $this->travel(-2)->days();

    takeBreak($this, 'cool_off', '30d')->assertCreated()->assertJsonPath('data.type', 'self_exclusion');

    expect($this->player->fresh()->activeExclusion()->type)->toBe('self_exclusion');
});

it('refuses a duration that does not belong to the type', function (string $type, string $duration) {
    takeBreak($this, $type, $duration)->assertUnprocessable()->assertJsonValidationErrors('duration');
})->with([
    'cool-off for 6 months' => ['cool_off', '6m'],
    'self-exclusion for a day' => ['self_exclusion', '24h'],
    'unknown' => ['cool_off', '2w'],
]);

it('ends game sessions when a break starts', function () {
    app(PlayerTokenService::class)->issue($this->player, Game::factory()->create(['code' => 'crash']));

    takeBreak($this, 'cool_off', '24h')->assertCreated();

    expect($this->player->tokens()->count())->toBe(0);
});

it('refuses a launch token for an excluded player', function () {
    Game::factory()->create(['code' => 'crash']);
    takeBreak($this, 'cool_off', '24h')->assertCreated();

    $this->postJson('/api/v1/customers/'.encryptId($this->player->id).'/launch-tokens', ['game' => 'crash'], apiHeaders($this->site->key))
        ->assertForbidden()
        ->assertJsonPath('code', 'player_excluded');
});

it('refuses to redeem a launch token issued before the break', function () {
    $crash = Game::factory()->create(['code' => 'crash']);
    $crashKey = ApiKey::create(['key' => 'game_crash_key', 'name' => 'crash', 'scope' => ApiKey::SCOPE_GAME, 'game_id' => $crash->id, 'is_active' => true]);
    $token = $this->postJson('/api/v1/customers/'.encryptId($this->player->id).'/launch-tokens', ['game' => 'crash'], apiHeaders($this->site->key))->json('data.token');

    takeBreak($this, 'cool_off', '24h')->assertCreated();

    $this->postJson('/api/v1/launch-tokens/redeem', ['token' => $token], apiHeaders($crashKey->key))
        ->assertForbidden()
        ->assertJsonPath('code', 'player_excluded');
});

it('shows kadi the responsible gambling state of a player', function () {
    // The break's end is compared with now() to the second: a second boundary between request and assertion would flake.
    $this->freezeTime();
    $this->getJson('/api/v1/customers/'.encryptId($this->player->id).'/responsible-gambling', apiHeaders($this->site->key))
        ->assertOk()
        ->assertJsonPath('data.status', 1)
        ->assertJsonPath('data.can_play', true)
        ->assertJsonPath('data.exclusion', null);

    takeBreak($this, 'cool_off', '24h')->assertCreated();

    $this->getJson('/api/v1/customers/'.encryptId($this->player->id).'/responsible-gambling', apiHeaders($this->site->key))
        ->assertOk()
        ->assertJsonPath('data.can_play', false)
        ->assertJsonPath('data.exclusion.type', 'cool_off')
        ->assertJsonPath('data.exclusion.ends_at', now()->addDay()->toIso8601String());
});

it('lists every break a customer took for GMS', function () {
    takeBreak($this, 'cool_off', '24h')->assertCreated();
    takeBreak($this, 'cool_off', '7d')->assertCreated();

    $this->getJson('/api/v1/customers/'.encryptId($this->player->id).'/exclusions', apiHeaders(createApiKey('gms-key')->key))
        ->assertOk()
        ->assertJsonPath('data.items.0.duration', '7d')
        ->assertJsonPath('data.pagination.total', 2);
});

it('keeps the break history off the site key', function () {
    $this->getJson('/api/v1/customers/'.encryptId($this->player->id).'/exclusions', apiHeaders($this->site->key))
        ->assertForbidden()
        ->assertJsonPath('code', 'route_not_allowed');
});

it('has no route that ends or shortens a break', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_contains($route->uri(), 'exclusion'))
        ->map(fn ($route) => implode('|', array_diff($route->methods(), ['HEAD'])).' '.$route->uri())
        ->sort()
        ->values()
        ->all();

    expect($routes)->toBe([
        'GET api/v1/customers/{encryptedIdentifier}/exclusions',
        'POST api/v1/customers/{encryptedIdentifier}/exclusion',
    ]);
});

it('gives a new account with the phone of an excluded player the same break', function () {
    $this->player->update(['phone_no' => '254712345678']);
    takeBreak($this, 'self_exclusion', '1y')->assertCreated();
    $endsAt = $this->player->fresh()->activeExclusion()->ends_at;

    // The excluded player moves their account to another number, then signs up again with the first one.
    $this->putJson('/api/v1/customers/'.encryptId($this->player->id), ['phone_no' => '254799000111'], apiHeaders($this->site->key))->assertOk();
    $this->postJson('/api/v1/customers', [
        'account_no' => 'KK-NEWACCOUNT01',
        'name' => 'Same Person',
        'email' => 'same.person@example.com',
        'phone_no' => '0712345678',
    ], apiHeaders($this->site->key))->assertCreated();

    $second = Customer::where('account_no', 'KK-NEWACCOUNT01')->sole();

    expect($second->activeExclusion()?->type)->toBe('self_exclusion')
        ->and($second->activeExclusion()->ends_at->equalTo($endsAt))->toBeTrue()
        ->and($this->gate->canPlay($second)->code)->toBe('player_excluded');
});

it('gives the break to an account that moves onto an excluded phone', function () {
    $this->player->update(['phone_no' => '254712345678']);
    takeBreak($this, 'cool_off', '30d')->assertCreated();
    $other = Customer::factory()->create(['status' => 1, 'phone_no' => '254733000222']);

    $this->putJson('/api/v1/customers/'.encryptId($other->id), ['phone_no' => '+254 712 345 678'], apiHeaders($this->site->key))->assertOk();

    expect($this->gate->canPlay($other->fresh())->code)->toBe('player_excluded');
});

it('gives a new account with the national ID of an excluded player the same break', function () {
    $this->player->update(['id_no' => '23456789']);
    takeBreak($this, 'cool_off', '7d')->assertCreated();
    $this->player->update(['id_no' => null]);

    $second = Customer::factory()->create(['status' => 1, 'id_no' => '23456789']);

    expect($this->gate->canPlay($second)->code)->toBe('player_excluded');
});

it('leaves other players alone', function () {
    $this->player->update(['phone_no' => '254712345678']);
    takeBreak($this, 'cool_off', '24h')->assertCreated();

    $other = Customer::factory()->create(['status' => 1, 'phone_no' => '254733000222']);

    expect($this->gate->canPlay($other)->allowed)->toBeTrue();
});

it('puts the player\'s other accounts with the same phone on the break too', function () {
    $this->player->update(['phone_no' => '254712345678']);
    $older = Customer::withoutEvents(fn () => Customer::factory()->create(['status' => 1, 'phone_no' => '0712345678', 'phone_hash' => Customer::phoneHash('0712345678')]));

    takeBreak($this, 'cool_off', '7d')->assertCreated();

    expect($this->gate->canPlay($older->fresh())->code)->toBe('player_excluded');
});
