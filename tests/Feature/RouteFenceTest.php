<?php

use App\Models\ApiKey;
use App\Models\BetShop;
use App\Models\Customer;
use App\Models\Game;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route as Router;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    $this->siteKey = ApiKey::create(['key' => 'site_test_key', 'name' => 'kadi site', 'scope' => ApiKey::SCOPE_SITE, 'is_active' => true]);
    $this->gameKey = ApiKey::create([
        'key' => 'game_test_key', 'name' => 'crash server', 'scope' => ApiKey::SCOPE_GAME,
        'game_id' => Game::factory()->create(['code' => 'crash'])->id, 'is_active' => true,
    ]);
});

/**
 * Every API route behind the API key check, as "METHOD uri" => [method, uri].
 *
 * @return array<string, array{0: string, 1: string}>
 */
function keyedRoutes(): array
{
    return collect(Router::getRoutes()->getRoutes())
        ->filter(fn (Route $route) => str_starts_with($route->uri(), 'api/v1/') && in_array('apikey.checker', $route->gatherMiddleware(), true))
        ->flatMap(fn (Route $route) => collect($route->methods())
            ->reject(fn (string $method) => $method === 'HEAD')
            ->mapWithKeys(fn (string $method) => ["{$method} {$route->uri()}" => [$method, $route->uri()]]))
        ->all();
}

/**
 * Refused by the route fence, or (only for routes behind auth:sanctum, which Laravel's middleware priority
 * runs before the API key check) refused with 401 before the fence is reached.
 */
function isFenced(object $test, array $route, ApiKey $key): bool
{
    $response = callRoute($test, $route[0], $route[1], $key);

    if ($response->json('code') === 'route_not_allowed') {
        return true;
    }

    $middleware = Router::getRoutes()->match(Request::create('/'.preg_replace('/\{[^}]+\}/', 'x', $route[1]), $route[0]))->gatherMiddleware();

    return in_array('auth:sanctum', $middleware, true) && $response->status() === 401;
}

function callRoute(object $test, string $method, string $uri, ApiKey $key)
{
    Cache::flush();

    return $test->json($method, '/'.preg_replace('/\{[^}]+\}/', 'x', $uri), [], apiHeaders($key->key));
}

it('refuses the site key on every route not in its list', function () {
    $refused = collect(keyedRoutes())->except(config('api_scopes.site'));

    expect($refused->count())->toBeGreaterThan(100);

    $leaks = $refused->reject(fn (array $route) => isFenced($this, $route, $this->siteKey));

    expect($leaks->keys()->all())->toBe([]);
});

it('refuses a game key on every route', function () {
    $leaks = collect(keyedRoutes())->except(config('api_scopes.game'))
        ->reject(fn (array $route) => isFenced($this, $route, $this->gameKey));

    expect($leaks->keys()->all())->toBe([]);
});

it('lets the site key reach every route kadi calls today', function () {
    $refused = collect(config('api_scopes.site'))
        ->map(fn (string $entry) => explode(' ', $entry, 2))
        ->filter(fn (array $route) => callRoute($this, $route[0], $route[1], $this->siteKey)->json('code') === 'route_not_allowed');

    expect($refused->all())->toBe([]);
});

it('answers a refused route with 403 route_not_allowed', function () {
    $customer = Customer::factory()->create();

    $this->putJson('/api/v1/customers/'.encryptId($customer->id).'/wallet', ['amount' => 1000], apiHeaders($this->siteKey->key))
        ->assertForbidden()
        ->assertExactJson(['message' => 'This API key cannot use this route', 'code' => 'route_not_allowed']);
});

it('lets HEAD through wherever GET is allowed', function () {
    $this->call('HEAD', '/api/v1/games', [], [], [], ['HTTP_X_API_KEY' => $this->siteKey->key, 'HTTP_ACCEPT' => 'application/json'])->assertOk();
});

it('refuses a method spoofed with _method', function () {
    $customer = Customer::factory()->create();

    $response = $this->postJson('/api/v1/customers/'.encryptId($customer->id), ['_method' => 'DELETE'], apiHeaders($this->siteKey->key));

    expect($response->status())->toBeIn([403, 405])
        ->and(Customer::find($customer->id))->not->toBeNull();
});

it('still lets full keys reach everything', function (string $method, string $uri) {
    $full = createApiKey('full-key-for-fence');

    expect(callRoute($this, $method, $uri, $full)->status())->not->toBe(403);
})->with([
    ['GET', 'api/v1/finance/summary'],
    ['GET', 'api/v1/deposits/unmatched'],
    ['GET', 'api/v1/api-keys'],
    ['GET', 'api/v1/customers'],
]);

it('keeps shop keys on shop routes', function () {
    $shop = BetShop::factory()->create();

    $this->getJson('/api/v1/games', apiHeaders(createShopKey($shop)->key))
        ->assertForbidden()
        ->assertExactJson(['message' => 'A shop API key can only be used on shop routes']);
});

it('refuses the site key the customer fields kadi never sends', function (string $field, mixed $value) {
    $customer = Customer::factory()->create(['status' => 0, 'account_no' => 'KK-FENCE0000001', 'referral_code' => 'FENCE1']);
    $before = $customer->only(['status', 'account_no', 'google_id', 'referral_code']);

    $this->putJson('/api/v1/customers/'.encryptId($customer->id), [$field => $value], apiHeaders($this->siteKey->key))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    expect($customer->fresh()->only(array_keys($before)))->toBe($before);
})->with([
    'un-suspend a player' => ['status', 1],
    'take another account number' => ['account_no', 'KK-FENCE0000002'],
    'google id' => ['google_id', 'g-123'],
    'referral code (has its own route)' => ['referral_code', 'FENCE2'],
]);

it('still lets the site key update what kadi sends, and a full key everything', function () {
    $customer = Customer::factory()->create(['status' => 1]);

    $this->putJson('/api/v1/customers/'.encryptId($customer->id), ['name' => 'Jane W', 'phone_no' => '254711000222'], apiHeaders($this->siteKey->key))
        ->assertOk();
    $this->putJson('/api/v1/customers/'.encryptId($customer->id), ['status' => 0], apiHeaders(createApiKey('full-fields-key')->key))
        ->assertOk();

    expect($customer->fresh())->name->toBe('Jane W')->phone_no->toBe('254711000222')->status->toBe(0);
});

it('lists only routes that exist', function () {
    $registered = array_keys(keyedRoutes());

    foreach (config('api_scopes') as $scope => $entries) {
        expect(array_diff($entries, $registered))->toBe([], "Unknown route in api_scopes.{$scope}");
    }
});
