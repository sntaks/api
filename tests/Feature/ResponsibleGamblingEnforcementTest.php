<?php

use App\Models\BetShop;
use App\Models\BetShopWallet;
use App\Models\CompetitionWallet;
use App\Models\Customer;
use App\Models\CustomerExclusion;
use App\Models\DepositLimit;
use App\Models\GameWallet;
use App\Models\LedgerEntry;
use App\Models\ReferralWallet;
use App\Models\RiskEvent;
use App\Models\StkPush;
use App\Models\Wallet;
use App\Services\DepositAllowance;
use App\Services\MpesaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->key = createApiKey('rg-full-key');
    $this->player = Customer::factory()->create([
        'status' => 1,
        'phone_no' => '254712345678',
        'account_no' => 'KK-RG0000000001',
        'id_no' => '12345678',
    ]);
    $this->player->forceFill(['phone_no_verified_at' => now()])->save();
    $this->wallet = Wallet::factory()->create(['customer_id' => $this->player->id, 'balance' => 10_000]);
    RateLimiter::clear('withdrawals:'.$this->player->id);
});

function exclude(Customer $customer): void
{
    CustomerExclusion::factory()->create(['customer_id' => $customer->id]);
}

function dailyLimit(Customer $customer, float $amount): void
{
    DepositLimit::create(['customer_id' => $customer->id, 'period' => 'day', 'amount' => $amount]);
}

function stakeOnGame(object $test, int $amount = 100)
{
    $game = GameWallet::create(['game_id' => 'RG_'.uniqid(), 'game_type' => 1, 'balance' => 0]);

    return $test->postJson('/api/v1/game/bets', [
        'game_wallet_id' => $game->id,
        'customer_id' => $test->player->id,
        'payment_type' => 'deposit',
        'amount' => $amount,
        'status' => 1,
    ], apiHeaders($test->key->key));
}

function stakeOnCompetition(object $test)
{
    $competition = CompetitionWallet::create([
        'competition_id' => 'TOURN-RG', 'cmp_uid' => (string) fake()->unique()->numberBetween(1000, 999999), 'game_type' => 1,
        'customer_id' => $test->player->id, 'level' => 2, 'jp_rounds' => 5, 'balance' => 0, 'status' => 1,
    ]);

    return $test->postJson('/api/v1/competition/transactions', [
        'competition_wallet_id' => $competition->id,
        'customer_id' => $test->player->id,
        'payment_type' => 'deposit',
        'amount' => 50,
    ], apiHeaders($test->key->key));
}

function withdrawMpesa(object $test, mixed $amount = 100)
{
    return $test->postJson('/api/v1/withdraw/'.encryptId($test->player->id), ['amount' => $amount], apiHeaders($test->key->key));
}

function mockB2cAccepted(object $test): void
{
    $mpesa = Mockery::mock(MpesaService::class);
    $mpesa->shouldReceive('b2c')->andReturnUsing(fn () => ['ResponseCode' => '0', 'ConversationID' => 'AG_'.uniqid()]);
    app()->instance(MpesaService::class, $mpesa);
}

// Stakes

it('refuses a game stake from an inactive or excluded player', function (Closure $disable, string $code) {
    $disable($this->player);

    stakeOnGame($this)->assertForbidden()->assertJsonPath('code', $code)->assertJsonStructure(['error', 'code']);

    expect((float) $this->wallet->fresh()->balance)->toBe(10_000.0)
        ->and(LedgerEntry::where('entry_type', 'game_bet')->count())->toBe(0);
})->with([
    'blocked' => [fn (Customer $customer) => $customer->update(['status' => 2]), 'player_inactive'],
    'lead' => [fn (Customer $customer) => $customer->update(['status' => 6]), 'player_inactive'],
    'on a break' => [fn (Customer $customer) => exclude($customer), 'player_excluded'],
]);

it('still takes a game stake from an active player', function () {
    stakeOnGame($this)->assertCreated();
});

it('refuses a competition stake from an excluded player', function () {
    exclude($this->player);

    stakeOnCompetition($this)->assertForbidden()->assertJsonPath('code', 'player_excluded')->assertJsonStructure(['status', 'code']);
});

it('refuses a wallet transfer from an excluded player', function () {
    exclude($this->player);
    $other = Wallet::factory()->create(['customer_id' => Customer::factory()->create()->id, 'balance' => 0]);

    $this->postJson('/api/v1/wallets/transfer/'.encryptId($this->wallet->id), ['amount' => 100, 'wallet_id' => $other->id], apiHeaders($this->key->key))
        ->assertForbidden()
        ->assertJsonPath('code', 'player_excluded');

    expect((float) $other->fresh()->balance)->toBe(0.0);
});

it('still settles a game for an excluded player', function () {
    // The settlement books the house cut against customer 1, as production has it (see GameWithdrawTest).
    $house = Customer::factory()->create(['id' => 1]);
    config(['wallets.house_wallet_id' => Wallet::factory()->create(['customer_id' => $house->id, 'balance' => 0])->id]);
    stakeOnGame($this)->assertCreated();
    $game = GameWallet::latest('id')->first();
    exclude($this->player);

    $this->postJson('/api/v1/game/withdraw/'.encryptId($game->id), ['customer_id' => (string) $this->player->id, 'amount' => 95], apiHeaders($this->key->key))
        ->assertCreated();
});

// Deposits

it('refuses an STK top-up during a break or over the limit', function (Closure $setUp, int $status, string $code) {
    $setUp($this->player);
    $this->mock(MpesaService::class)->shouldNotReceive('stkPush');

    $this->postJson('/api/v1/deposits/'.encryptId($this->player->id), ['amount' => 600], apiHeaders($this->key->key))
        ->assertStatus($status)
        ->assertJsonPath('code', $code);
})->with([
    'on a break' => [fn (Customer $customer) => exclude($customer), 403, 'player_excluded'],
    'suspended' => [fn (Customer $customer) => $customer->update(['status' => 5]), 403, 'player_inactive'],
    'over the limit' => [fn (Customer $customer) => dailyLimit($customer, 500), 422, 'deposit_limit'],
]);

it('says how much is left when a top-up is over the limit', function () {
    dailyLimit($this->player, 500);
    $this->mock(MpesaService::class)->shouldNotReceive('stkPush');

    $this->postJson('/api/v1/deposits/'.encryptId($this->player->id), ['amount' => 600], apiHeaders($this->key->key))
        ->assertJsonPath('remaining', 500);
});

it('credits a paybill deposit over the limit and flags it', function () {
    dailyLimit($this->player, 50);

    payPaybill($this->player->player_no, hash('sha256', '254712345678'), 'RGC2B0001', 100)->assertStatus(201);

    expect((float) $this->wallet->fresh()->balance)->toBeGreaterThan(10_000.0);

    $flag = RiskEvent::sole();
    expect($flag->event_type)->toBe('rg_deposit_over_limit')
        ->and($flag->customer_id)->toBe($this->player->id)
        ->and($flag->details['amount'])->toEqual(100)
        ->and($flag->details['remaining'])->toEqual(50);
});

it('credits a paybill deposit during a break and flags it', function () {
    exclude($this->player);

    payPaybill($this->player->player_no, hash('sha256', '254712345678'), 'RGC2B0002', 100)->assertStatus(201);

    expect(RiskEvent::sole()->event_type)->toBe('rg_deposit_while_excluded');
});

it('flags nothing for an ordinary paybill deposit', function () {
    payPaybill($this->player->player_no, hash('sha256', '254712345678'), 'RGC2B0003', 100)->assertStatus(201);

    expect(RiskEvent::count())->toBe(0);
});

it('lists and resolves the flags for GMS', function () {
    exclude($this->player);
    payPaybill($this->player->player_no, hash('sha256', '254712345678'), 'RGC2B0004', 100)->assertStatus(201);
    $flag = RiskEvent::sole();

    $this->getJson('/api/v1/responsible-gambling/flags?resolved=0', apiHeaders($this->key->key))
        ->assertOk()
        ->assertJsonPath('data.items.0.id', $flag->id)
        ->assertJsonPath('data.items.0.type', 'rg_deposit_while_excluded');

    $this->postJson("/api/v1/responsible-gambling/flags/{$flag->id}/resolve", ['admin_user' => 'ann', 'note' => 'Refunded by M-Pesa reversal'], apiHeaders($this->key->key))
        ->assertOk();

    expect($flag->fresh()->resolved_at)->not->toBeNull()
        ->and($flag->fresh()->details['resolution']['admin_user'])->toBe('ann');

    $this->getJson('/api/v1/responsible-gambling/flags?resolved=0', apiHeaders($this->key->key))->assertJsonPath('data.pagination.total', 0);
});

it('refuses a shop load to an excluded player or over the limit', function (Closure $setUp, int $status, string $code) {
    $shop = BetShop::factory()->create();
    BetShopWallet::create(['bet_shop_id' => $shop->id, 'balance' => 10_000]);
    $setUp($this->player);

    $this->postJson('/api/v1/shop/loads', ['player_no' => $this->player->player_no, 'amount' => 600], shopHeaders(createShopKey($shop), 'rg-load-1'))
        ->assertStatus($status)
        ->assertJsonPath('code', $code);
})->with([
    'on a break' => [fn (Customer $customer) => exclude($customer), 403, 'player_excluded'],
    'over the limit' => [fn (Customer $customer) => dailyLimit($customer, 500), 422, 'deposit_limit'],
]);

// Withdrawals

it('refuses a withdrawal outside 50 to 150,000 or not whole shillings', function (mixed $amount) {
    withdrawMpesa($this, $amount)->assertUnprocessable();
})->with([49, 150_001, 100.5]);

it('refuses a withdrawal from a suspended player', function (int $status) {
    $this->player->update(['status' => $status]);

    withdrawMpesa($this)->assertForbidden()->assertJsonPath('code', 'player_suspended');
})->with([2, 5]);

it('still lets an excluded or closed player withdraw', function (Closure $setUp) {
    mockB2cAccepted($this);
    $setUp($this->player);

    withdrawMpesa($this)->assertCreated();
})->with([
    'on a break' => [fn (Customer $customer) => exclude($customer)],
    'closed' => [fn (Customer $customer) => $customer->update(['status' => 4])],
]);

it('refuses a withdrawal to an unverified phone', function () {
    $this->player->forceFill(['phone_no_verified_at' => null])->save();

    withdrawMpesa($this)->assertUnprocessable()->assertJsonPath('code', 'phone_unverified');

    expect((float) $this->wallet->fresh()->balance)->toBe(10_000.0);
});

it('allows five withdrawals an hour', function () {
    mockB2cAccepted($this);

    // A minute apart: the route also has a per-key limit of 5 a minute (throttle:financial).
    foreach (range(1, 5) as $i) {
        withdrawMpesa($this)->assertCreated();
        $this->travel(61)->seconds();
    }

    withdrawMpesa($this)->assertStatus(429)->assertJsonPath('code', 'too_many_withdrawals')->assertJsonStructure(['retry_after']);

    $this->travel(61)->minutes();

    withdrawMpesa($this)->assertCreated();
});

it('shares the hourly withdrawal cap with referral withdrawals', function () {
    mockB2cAccepted($this);
    // A minute apart: the route also has a per-key limit of 5 a minute (throttle:financial).
    foreach (range(1, 5) as $i) {
        withdrawMpesa($this)->assertCreated();
        $this->travel(61)->seconds();
    }
    ReferralWallet::create(['customer_id' => $this->player->id, 'balance' => 500]);

    $this->postJson('/api/v1/customers/'.encryptId($this->player->id).'/referral-wallet/withdraw', ['amount' => 100], apiHeaders($this->key->key))
        ->assertStatus(429)
        ->assertJsonPath('code', 'too_many_withdrawals');
});

it('applies the withdrawal rules to referral withdrawals', function (Closure $setUp, mixed $amount, int $status, ?string $code) {
    ReferralWallet::create(['customer_id' => $this->player->id, 'balance' => 500_000]);
    $setUp($this->player);

    $response = $this->postJson('/api/v1/customers/'.encryptId($this->player->id).'/referral-wallet/withdraw', ['amount' => $amount], apiHeaders($this->key->key))
        ->assertStatus($status);

    if ($code) {
        $response->assertJsonPath('code', $code);
    }
})->with([
    'suspended' => [fn (Customer $customer) => $customer->update(['status' => 2]), 100, 403, 'player_suspended'],
    'unverified phone' => [fn (Customer $customer) => $customer->forceFill(['phone_no_verified_at' => null])->save(), 100, 422, 'phone_unverified'],
    'above the maximum' => [fn () => null, 150_001, 422, null],
]);

it('refuses a shop cash withdrawal from a suspended player', function () {
    $shop = BetShop::factory()->create();
    $this->player->update(['status' => 5]);

    $this->postJson('/api/v1/customers/'.encryptId($this->player->id).'/shop-withdrawals', ['shop_no' => $shop->shop_no, 'amount' => 500], apiHeaders($this->key->key))
        ->assertForbidden()
        ->assertJsonPath('code', 'player_suspended');
});

it('lets a closed player withdraw cash at a shop', function () {
    $shop = BetShop::factory()->create();
    $this->player->update(['status' => 4]);

    $this->postJson('/api/v1/customers/'.encryptId($this->player->id).'/shop-withdrawals', ['shop_no' => $shop->shop_no, 'amount' => 500], apiHeaders($this->key->key))
        ->assertCreated();
});

it('does not flag an STK top-up when its own money arrives', function () {
    dailyLimit($this->player, 1000);
    $mpesa = Mockery::mock(MpesaService::class);
    $mpesa->shouldReceive('stkPush')->andReturn(['ResponseCode' => '0', 'CheckoutRequestID' => 'ws_CO_RG1']);
    app()->instance(MpesaService::class, $mpesa);

    $this->postJson('/api/v1/deposits/'.encryptId($this->player->id), ['amount' => 600], apiHeaders($this->key->key))->assertCreated();
    payPaybill($this->player->player_no, hash('sha256', '254712345678'), 'RGSTK0001', 600)->assertStatus(201);

    expect(RiskEvent::count())->toBe(0)
        ->and(StkPush::sole()->settled_at)->not->toBeNull()
        ->and(app(DepositAllowance::class)->remaining($this->player->fresh()))->toBe(400.0);
});

it('flags a manually recorded paybill deposit the player rules would refuse', function () {
    exclude($this->player);

    $this->postJson('/api/v1/deposits', [
        'TransID' => 'RGMAN0001',
        'TransactionType' => 'Pay Bill',
        'TransTime' => now()->toDateTimeString(),
        'TransAmount' => 100,
        'BusinessShortCode' => '12345',
        'BillRefNumber' => $this->player->player_no,
        'MSISDN' => hash('sha256', '254712345678'),
        'FirstName' => 'Jane',
    ], apiHeaders($this->key->key))->assertCreated();

    expect(RiskEvent::sole()->event_type)->toBe('rg_deposit_while_excluded');
});

it('tells the shop how much the player can still load', function () {
    $shop = BetShop::factory()->create();
    BetShopWallet::create(['bet_shop_id' => $shop->id, 'balance' => 10_000]);
    dailyLimit($this->player, 500);

    $this->postJson('/api/v1/shop/loads', ['player_no' => $this->player->player_no, 'amount' => 600], shopHeaders(createShopKey($shop), 'rg-load-2'))
        ->assertStatus(422)
        ->assertJsonPath('remaining', 500);
});
