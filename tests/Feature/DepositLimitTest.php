<?php

use App\Models\ApiKey;
use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\StkPush;
use App\Models\Wallet;
use App\Services\DepositAllowance;
use App\Services\MpesaService;
use App\Services\PlayerGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->site = ApiKey::create(['key' => 'site_test_key', 'name' => 'kadi-site', 'scope' => ApiKey::SCOPE_SITE, 'is_active' => true]);
    $this->player = Customer::factory()->create(['status' => 1, 'phone_no' => '254712345678']);
    $this->wallet = Wallet::factory()->create(['customer_id' => $this->player->id, 'balance' => 0]);
    $this->allowance = app(DepositAllowance::class);
});

function setLimit(object $test, string $period, mixed $amount)
{
    return $test->putJson('/api/v1/customers/'.encryptId($test->player->id).'/deposit-limits/'.$period, ['amount' => $amount], apiHeaders($test->site->key));
}

/**
 * A ledger line on the player's wallet, as a deposit, shop load or their reversal would post it.
 */
function walletEntry(object $test, string $type, float $credit, float $debit = 0): void
{
    LedgerEntry::create([
        'entry_id' => (string) Str::uuid(),
        'entry_type' => $type,
        'wallet_type' => LedgerEntry::WALLET_TYPE_WALLET,
        'wallet_id' => $test->wallet->id,
        'customer_id' => $test->player->id,
        'debit' => $debit,
        'credit' => $credit,
        'balance_before' => 0,
        'balance_after' => 0,
        'status' => 'settled',
    ]);
}

it('applies a first limit at once', function () {
    setLimit($this, 'day', 1000)
        ->assertOk()
        ->assertJsonPath('data.period', 'day')
        ->assertJsonPath('data.amount', 1000)
        ->assertJsonPath('data.pending_amount', null)
        ->assertJsonPath('data.pending_from', null);

    expect($this->allowance->remaining($this->player))->toBe(1000.0);
});

it('applies a lower limit at once', function () {
    setLimit($this, 'day', 1000)->assertOk();

    setLimit($this, 'day', 500)->assertOk()->assertJsonPath('data.amount', 500);

    expect($this->allowance->remaining($this->player))->toBe(500.0);
});

it('delays a higher limit by 24 hours', function () {
    $this->freezeTime();
    setLimit($this, 'day', 1000)->assertOk();

    setLimit($this, 'day', 5000)
        ->assertOk()
        ->assertJsonPath('data.amount', 1000)
        ->assertJsonPath('data.pending_amount', 5000)
        ->assertJsonPath('data.pending_from', now()->addHours(24)->toIso8601String());

    expect($this->allowance->remaining($this->player))->toBe(1000.0);

    $this->travel(24)->hours();

    expect($this->allowance->remaining($this->player))->toBe(5000.0);
});

it('delays removing a limit by 24 hours', function () {
    setLimit($this, 'day', 1000)->assertOk();

    setLimit($this, 'day', null)->assertOk()->assertJsonPath('data.amount', 1000)->assertJsonPath('data.pending_amount', null);

    expect($this->allowance->remaining($this->player))->toBe(1000.0);

    $this->travel(25)->hours();

    expect($this->allowance->remaining($this->player))->toBeNull();
});

it('counts deposits and shop loads in the rolling window, net of reversals', function () {
    setLimit($this, 'day', 1000)->assertOk();
    walletEntry($this, 'deposit', 600);
    walletEntry($this, 'shop_load', 200);
    walletEntry($this, 'deposit_reversal', 0, 100);
    walletEntry($this, 'game_payout', 5000);

    expect($this->allowance->remaining($this->player))->toBe(300.0);

    $this->travel(25)->hours();

    expect($this->allowance->remaining($this->player))->toBe(1000.0);
});

it('counts a recent STK push until its money is due', function () {
    setLimit($this, 'day', 1000)->assertOk();
    $this->mock(MpesaService::class)->shouldReceive('stkPush')->andReturn(['ResponseCode' => '0', 'CheckoutRequestID' => 'ws_CO_1']);

    $this->postJson('/api/v1/deposits/'.encryptId($this->player->id), ['amount' => 600], apiHeaders(createApiKey()->key))->assertCreated();

    expect(StkPush::sole()->checkout_request_id)->toBe('ws_CO_1')
        ->and($this->allowance->remaining($this->player))->toBe(400.0);

    $this->travel(4)->minutes();

    expect($this->allowance->remaining($this->player))->toBe(1000.0);
});

it('uses the tightest of several limits', function () {
    setLimit($this, 'day', 1000)->assertOk();
    setLimit($this, 'week', 700)->assertOk();
    setLimit($this, 'month', 5000)->assertOk();
    walletEntry($this, 'deposit', 200);

    expect($this->allowance->remaining($this->player))->toBe(500.0);
});

it('has no limit until one is set', function () {
    expect($this->allowance->remaining($this->player))->toBeNull();
});

it('refuses a deposit above what the limits leave', function () {
    setLimit($this, 'day', 1000)->assertOk();
    walletEntry($this, 'deposit', 800);
    $gate = app(PlayerGate::class);

    $refused = $gate->canDeposit($this->player, 300);

    expect($refused->allowed)->toBeFalse()
        ->and($refused->code)->toBe('deposit_limit')
        ->and($refused->statusCode)->toBe(422)
        ->and($refused->extra['remaining'])->toBe(200.0)
        ->and($gate->canDeposit($this->player, 200)->allowed)->toBeTrue();
});

it('refuses a deposit while the player is on a break or inactive', function () {
    $gate = app(PlayerGate::class);
    $this->player->update(['status' => 2]);

    expect($gate->canDeposit($this->player, 10)->code)->toBe('player_inactive');
});

it('refuses a bad limit', function (mixed $amount) {
    setLimit($this, 'day', $amount)->assertUnprocessable()->assertJsonValidationErrors('amount');
})->with([
    'below 10' => [9],
    'not whole shillings' => [10.5],
    'above the maximum' => [1_000_001],
]);

it('prunes STK pushes older than a month', function () {
    StkPush::create(['customer_id' => $this->player->id, 'amount' => 100]);
    $this->travel(32)->days();
    StkPush::create(['customer_id' => $this->player->id, 'amount' => 200]);

    $this->artisan('stk-pushes:prune')->assertSuccessful();

    expect(StkPush::sole()->amount)->toBe('200.00');
});

it('answers 404 for an unknown period', function () {
    setLimit($this, 'year', 1000)->assertNotFound();
});

it('shows the limits and what remains on the responsible gambling summary', function () {
    setLimit($this, 'day', 1000)->assertOk();
    walletEntry($this, 'deposit', 250);

    $this->getJson('/api/v1/customers/'.encryptId($this->player->id).'/responsible-gambling', apiHeaders($this->site->key))
        ->assertOk()
        ->assertJsonPath('data.deposit_limits.0.period', 'day')
        ->assertJsonPath('data.deposit_limits.0.amount', 1000)
        ->assertJsonPath('data.deposit_limits.0.remaining', 750)
        ->assertJsonPath('data.deposit_remaining', 750);
});
