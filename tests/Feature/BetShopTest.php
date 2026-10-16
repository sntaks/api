<?php

use App\Models\ApiKey;
use App\Models\BetShop;
use App\Models\BetShopWallet;
use App\Models\Customer;
use App\Util\PlayerNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->gms = apiHeaders(createApiKey('test-bet-shops-gms-key')->key);
});

// onboarding

it('onboards a shop with an empty float and the bill ref the owner pays with', function () {
    $response = $this->postJson('/api/v1/bet-shops', [
        'shop_no' => ' s101 ', 'name' => 'Kariobangi Bet Shop', 'owner_name' => 'Peter Mwangi',
        'owner_phone' => '0712345678', 'location' => 'Kariobangi North',
    ], $this->gms);

    $response->assertCreated()->assertJson([
        'success' => true,
        'data' => [
            'shop_no' => 'S101', 'float_bill_ref' => 'SHOP-S101', 'name' => 'Kariobangi Bet Shop', 'owner_phone' => '254712345678',
            'status' => 'active', 'float_balance' => 0, 'active_keys' => 0,
        ],
    ]);

    $shop = BetShop::where('shop_no', 'S101')->sole();
    expect(BetShopWallet::where('bet_shop_id', $shop->id)->sole()->balance)->toBe('0.00')
        ->and($shop->created_by)->toStartWith('api_key:');
});

it('validates a new shop', function (array $override, string $field) {
    BetShop::factory()->create(['shop_no' => 'TAKEN']);

    $this->postJson('/api/v1/bet-shops', $override + [
        'shop_no' => 'S102', 'name' => 'Shop', 'owner_name' => 'Owner', 'owner_phone' => '254712345678',
    ], $this->gms)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'shop number taken (any case)' => [['shop_no' => 'taken'], 'shop_no'],
    'shop number with symbols' => [['shop_no' => 'S-1'], 'shop_no'],
    'shop number too long' => [['shop_no' => 'ABCDEFGHIJK'], 'shop_no'],
    'not a Kenyan mobile' => [['owner_phone' => '12345'], 'owner_phone'],
    'no owner' => [['owner_name' => ''], 'owner_name'],
]);

it('lists, shows and updates shops, but never changes the shop number', function () {
    $shop = BetShop::factory()->withFloat(1500)->create(['shop_no' => 'S200', 'name' => 'Old Name']);
    BetShop::factory()->suspended()->withFloat()->create(['shop_no' => 'S201']);

    $this->getJson('/api/v1/bet-shops?status=active', $this->gms)->assertOk()
        ->assertJsonPath('data.pagination.total', 1)
        ->assertJsonPath('data.items.0.shop_no', 'S200')
        ->assertJsonPath('data.items.0.float_balance', 1500);

    $this->getJson('/api/v1/bet-shops/'.encryptId($shop->id), $this->gms)->assertOk()->assertJsonPath('data.name', 'Old Name');

    $this->putJson('/api/v1/bet-shops/'.encryptId($shop->id), ['name' => 'New Name', 'shop_no' => 'S999', 'owner_phone' => '+254 798 765 432'], $this->gms)
        ->assertOk()
        ->assertJsonPath('data.name', 'New Name')
        ->assertJsonPath('data.shop_no', 'S200')
        ->assertJsonPath('data.owner_phone', '254798765432');
});

// status

it('suspends and reactivates a shop', function () {
    $shop = BetShop::factory()->withFloat()->create();

    $this->postJson('/api/v1/bet-shops/'.encryptId($shop->id).'/suspend', [], $this->gms)->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->postJson('/api/v1/bet-shops/'.encryptId($shop->id).'/suspend', ['reason' => 'Owner did not report'], $this->gms)
        ->assertOk()->assertJsonPath('data.status', 'suspended')->assertJsonPath('data.status_reason', 'Owner did not report');
    $this->postJson('/api/v1/bet-shops/'.encryptId($shop->id).'/activate', [], $this->gms)
        ->assertOk()->assertJsonPath('data.status', 'active');
});

it('closes a shop only once its float is empty, and closing is final', function () {
    $shop = BetShop::factory()->withFloat(200)->create();
    $key = createShopKey($shop);

    $this->postJson('/api/v1/bet-shops/'.encryptId($shop->id).'/close', ['reason' => 'Moved away'], $this->gms)
        ->assertConflict()->assertJsonPath('code', 'float_not_empty');

    BetShopWallet::where('bet_shop_id', $shop->id)->update(['balance' => 0]);

    $this->postJson('/api/v1/bet-shops/'.encryptId($shop->id).'/close', ['reason' => 'Moved away'], $this->gms)
        ->assertOk()->assertJsonPath('data.status', 'closed')->assertJsonPath('data.active_keys', 0);

    expect($key->fresh()->is_active)->toBeFalse();

    $this->postJson('/api/v1/bet-shops/'.encryptId($shop->id).'/activate', [], $this->gms)
        ->assertConflict()->assertJsonPath('code', 'shop_closed');
});

// keys

it('issues a shop key once and lists it without the key', function () {
    $shop = BetShop::factory()->withFloat()->create();

    $issued = $this->postJson('/api/v1/bet-shops/'.encryptId($shop->id).'/keys', ['name' => 'Front desk'], $this->gms)
        ->assertCreated()
        ->assertJsonPath('data.name', 'Front desk')
        ->assertJsonPath('data.is_active', true)
        ->json('data');

    expect($issued['key'])->toStartWith('shop_')->toHaveLength(45)
        ->and($issued['key_hint'])->toBe('…'.substr($issued['key'], -4));

    $listed = $this->getJson('/api/v1/bet-shops/'.encryptId($shop->id).'/keys', $this->gms)->assertOk()->json('data');
    expect($listed)->toHaveCount(1)
        ->and($listed[0])->not->toHaveKey('key')
        ->and(ApiKey::find($issued['id']))->toMatchArray(['scope' => 'shop', 'bet_shop_id' => $shop->id]);

    $this->getJson('/api/v1/shop/me', apiHeaders($issued['key']))->assertOk()->assertJsonPath('data.shop_no', $shop->shop_no);
});

it('revokes a shop key, which then stops working', function () {
    $shop = BetShop::factory()->withFloat()->create();
    $key = createShopKey($shop);

    $this->postJson('/api/v1/bet-shop-keys/'.encryptId($key->id).'/revoke', [], $this->gms)
        ->assertOk()->assertJsonPath('data.is_active', false);

    expect($key->fresh()->revoked_by)->toStartWith('api_key:');
    $this->getJson('/api/v1/shop/me', apiHeaders($key->key))->assertUnauthorized();
});

it('does not revoke a full key through the shop key route', function () {
    $full = ApiKey::where('key', 'test-bet-shops-gms-key')->sole();

    $this->postJson('/api/v1/bet-shop-keys/'.encryptId($full->id).'/revoke', [], $this->gms)->assertNotFound();
    expect($full->fresh()->is_active)->toBeTrue();
});

// scoping

it('keeps a shop key on shop routes', function (string $method, string $uri) {
    $key = createShopKey(BetShop::factory()->withFloat()->create());

    $this->json($method, $uri, [], apiHeaders($key->key))
        ->assertForbidden()
        ->assertJson(['message' => 'A shop API key can only be used on shop routes']);
})->with([
    'customers' => ['GET', '/api/v1/customers'],
    'finance' => ['GET', '/api/v1/finance/summary'],
    'set a wallet balance' => ['PUT', '/api/v1/wallets/abc/balance'],
    'B2C send' => ['POST', '/api/v1/b2c/send'],
    'bet shop admin' => ['GET', '/api/v1/bet-shops'],
]);

it('keeps full keys off shop routes', function () {
    $this->getJson('/api/v1/shop/me', $this->gms)
        ->assertForbidden()
        ->assertJson(['message' => 'Shop routes need a shop API key']);
});

it('refuses a suspended shop\'s key', function () {
    $shop = BetShop::factory()->suspended()->withFloat()->create();

    $this->getJson('/api/v1/shop/me', apiHeaders(createShopKey($shop)->key))
        ->assertForbidden()
        ->assertJsonPath('code', 'shop_not_active');
});

it('shows the shop its own float, today and limits', function () {
    $shop = BetShop::factory()->withFloat(2500)->create(['shop_no' => 'S300']);

    $this->getJson('/api/v1/shop/me', apiHeaders(createShopKey($shop)->key))
        ->assertOk()
        ->assertJson(['data' => [
            'shop_no' => 'S300', 'float_balance' => 2500, 'float_bill_ref' => 'SHOP-S300',
            'today' => ['loads' => 0, 'gross_amount' => 0],
            'load_limits' => ['min_amount' => 50, 'max_amount' => 20000, 'daily_limit_per_player' => 50000],
        ]]);
});

// player numbers

it('gives every new customer a valid player number and returns it', function () {
    $customer = Customer::factory()->create();

    expect(PlayerNumber::normalize($customer->player_no))->toBe($customer->player_no);

    $this->getJson('/api/v1/customers/'.encryptId($customer->id), $this->gms)
        ->assertOk()
        ->assertJsonPath('data.player_no', $customer->player_no);
});
