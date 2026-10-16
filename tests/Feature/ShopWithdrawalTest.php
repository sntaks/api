<?php

use App\Models\BetShop;
use App\Models\BetShopWallet;
use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\ShopWithdrawal;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Services\CustomerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    config(['finance.test_customer_ids' => []]);

    $this->client = apiHeaders(createApiKey('test-shop-withdrawals-client-key')->key);
    $this->shop = BetShop::factory()->withFloat(1000)->create(['shop_no' => 'S100']);
    $this->key = createShopKey($this->shop);
    $this->player = Customer::factory()->create(['name' => 'Grace Wanjiku', 'status' => 1, 'id_no' => '12345678']);
    Wallet::factory()->create(['customer_id' => $this->player->id, 'balance' => 3000]);
});

function askForCash(object $test, int $amount = 1000, string $shopNo = 'S100', ?Customer $player = null)
{
    return $test->postJson('/api/v1/customers/'.encryptId(($player ?? $test->player)->id).'/shop-withdrawals', [
        'shop_no' => $shopNo,
        'amount' => $amount,
    ], $test->client);
}

function payCash(object $test, string $withdrawalId, string $code, string $idempotencyKey, ?object $key = null)
{
    return $test->postJson("/api/v1/shop/withdrawals/{$withdrawalId}/pay", ['pickup_code' => $code], shopHeaders($key ?? $test->key, $idempotencyKey));
}

function playerBalance(Customer $customer): float
{
    return (float) Wallet::where('customer_id', $customer->id)->value('balance');
}

function shopFloatBalance(BetShop $shop): float
{
    return (float) BetShopWallet::where('bet_shop_id', $shop->id)->value('balance');
}

/**
 * A pickup code that is not $code.
 */
function wrongCode(string $code): string
{
    return $code === '000000' ? '111111' : '000000';
}

// asking

it('takes the money out of the wallet and gives the player a pickup code once', function () {
    $data = askForCash($this, 1000)->assertCreated()->json('data');

    expect($data['pickup_code'])->toMatch('/^\d{6}$/')
        ->and($data)->toMatchArray(['shop_no' => 'S100', 'amount' => 1000, 'status' => 'pending'])
        ->and(playerBalance($this->player))->toBe(2000.0);

    $withdrawal = ShopWithdrawal::sole();
    expect((float) $withdrawal->balance)->toBe(1000.0)
        ->and($withdrawal->expires_at->toDateTimeString())->toBe(now()->addHours(24)->toDateTimeString())
        ->and(LedgerEntry::where('entry_type', 'shop_withdrawal_hold')->pluck('wallet_type')->all())->toBe(['wallet', 'shop_withdrawal'])
        ->and(Transaction::sole())->toMatchArray(['payment_type' => ShopWithdrawal::class, 'amount' => '1000.00', 'status' => 1]);

    $listed = $this->getJson('/api/v1/customers/'.encryptId($this->player->id).'/shop-withdrawals', $this->client)->assertOk()->json('data.items.0');
    expect($listed)->not->toHaveKey('pickup_code')->and($listed['withdrawal_id'])->toBe($data['withdrawal_id']);
});

it('refuses a withdrawal the player cannot make', function (Closure $setUp, int $amount, int $status, string $code) {
    $setUp($this);

    askForCash($this, $amount)->assertStatus($status)->assertJsonPath('code', $code);

    expect(ShopWithdrawal::count())->toBe(0)->and(playerBalance($this->player))->toBe(3000.0);
})->with([
    'no ID number on file' => [fn ($test) => $test->player->update(['id_no' => null]), 500, 422, 'id_required'],
    'more than the wallet holds' => [fn ($test) => null, 3001, 409, 'insufficient_balance'],
    'inactive account' => [fn ($test) => $test->player->update(['status' => 0]), 500, 409, 'player_inactive'],
    'suspended shop' => [fn ($test) => $test->shop->update(['status' => BetShop::STATUS_SUSPENDED]), 500, 409, 'shop_not_active'],
]);

it('validates the shop and amount', function () {
    askForCash($this, 500, 'NOPE')->assertNotFound()->assertJsonPath('code', 'shop_not_found');
    askForCash($this, 49)->assertUnprocessable()->assertJsonValidationErrors('amount');
    askForCash($this, 20001)->assertUnprocessable()->assertJsonValidationErrors('amount');
});

// the shop

it('shows the shop its own pending requests, masked and without the code', function () {
    askForCash($this, 500)->assertCreated();
    $other = BetShop::factory()->withFloat()->create(['shop_no' => 'S200']);
    askForCash($this, 300, 'S200')->assertCreated();

    $items = $this->getJson('/api/v1/shop/withdrawals', apiHeaders($this->key->key))->assertOk()->json('data.items');

    expect($items)->toHaveCount(1)
        ->and($items[0])->toMatchArray(['amount' => 500, 'status' => 'pending', 'player' => ['player_no' => $this->player->player_no, 'name' => 'Grace W****']])
        ->and($items[0])->not->toHaveKey('pickup_code')
        ->and($items[0])->not->toHaveKey('customer_name');
});

it('pays the player against the pickup code and moves the money to the shop float', function () {
    $asked = askForCash($this, 1000)->json('data');

    payCash($this, $asked['withdrawal_id'], $asked['pickup_code'], 'pay-1')
        ->assertOk()
        ->assertJsonPath('message', 'Paid. Hand the player KES 1,000')
        ->assertJsonPath('data.status', 'paid')
        ->assertJsonPath('data.float_balance', 2000);

    $withdrawal = ShopWithdrawal::sole();
    expect((float) $withdrawal->balance)->toBe(0.0)
        ->and(shopFloatBalance($this->shop))->toBe(2000.0)
        ->and(playerBalance($this->player))->toBe(2000.0)
        ->and($withdrawal->closed_by)->toBe('api_key:'.$this->key->id)
        ->and(Transaction::sole()->status)->toBe(2);

    payCash($this, $asked['withdrawal_id'], $asked['pickup_code'], 'pay-1')->assertOk();
    payCash($this, $asked['withdrawal_id'], $asked['pickup_code'], 'pay-2')->assertConflict()->assertJsonPath('code', 'not_pending');
    expect(shopFloatBalance($this->shop))->toBe(2000.0);
});

it('needs an Idempotency-Key to pay', function () {
    $asked = askForCash($this, 500)->json('data');

    $this->postJson("/api/v1/shop/withdrawals/{$asked['withdrawal_id']}/pay", ['pickup_code' => $asked['pickup_code']], apiHeaders($this->key->key))
        ->assertStatus(400)->assertJsonPath('code', 'idempotency_key_required');
});

it('does not let another shop pay a request', function () {
    $asked = askForCash($this, 500)->json('data');
    $otherKey = createShopKey(BetShop::factory()->withFloat()->create(['shop_no' => 'S200']));

    payCash($this, $asked['withdrawal_id'], $asked['pickup_code'], 'other-shop', $otherKey)->assertNotFound();

    expect(ShopWithdrawal::sole()->status)->toBe('pending');
});

it('cancels the request after too many wrong codes and gives the money back', function () {
    $asked = askForCash($this, 1000)->json('data');
    $wrong = wrongCode($asked['pickup_code']);

    foreach (range(1, 4) as $attempt) {
        payCash($this, $asked['withdrawal_id'], $wrong, "wrong-{$attempt}")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'wrong_code')
            ->assertJsonPath('attempts_left', 5 - $attempt);
    }

    payCash($this, $asked['withdrawal_id'], $wrong, 'wrong-5')->assertConflict()->assertJsonPath('code', 'too_many_attempts');

    expect(ShopWithdrawal::sole())->toMatchArray(['status' => 'cancelled', 'failed_attempts' => 5, 'close_reason' => 'Too many wrong pickup codes'])
        ->and(playerBalance($this->player))->toBe(3000.0)
        ->and(shopFloatBalance($this->shop))->toBe(1000.0);

    payCash($this, $asked['withdrawal_id'], $asked['pickup_code'], 'right-too-late')->assertConflict()->assertJsonPath('code', 'not_pending');
});

it('lets the shop reject a request, giving the money back', function () {
    $asked = askForCash($this, 1000)->json('data');

    $this->postJson("/api/v1/shop/withdrawals/{$asked['withdrawal_id']}/reject", [], shopHeaders($this->key, 'reject-0'))->assertUnprocessable();
    $this->postJson("/api/v1/shop/withdrawals/{$asked['withdrawal_id']}/reject", ['reason' => 'Not enough cash in the till'], shopHeaders($this->key, 'reject-1'))
        ->assertOk()->assertJsonPath('data.status', 'rejected');

    expect(playerBalance($this->player))->toBe(3000.0)
        ->and(ShopWithdrawal::sole()->close_reason)->toBe('Not enough cash in the till')
        ->and(Transaction::sole()->status)->toBe(3);
});

it('lets the player cancel a pending request, but not a paid one', function () {
    $pending = askForCash($this, 500)->json('data');
    $paid = askForCash($this, 700)->json('data');
    payCash($this, $paid['withdrawal_id'], $paid['pickup_code'], 'pay-paid')->assertOk();

    $this->postJson('/api/v1/customers/'.encryptId($this->player->id)."/shop-withdrawals/{$pending['withdrawal_id']}/cancel", [], $this->client)
        ->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->postJson('/api/v1/customers/'.encryptId($this->player->id)."/shop-withdrawals/{$paid['withdrawal_id']}/cancel", [], $this->client)
        ->assertConflict()->assertJsonPath('code', 'not_pending');

    expect(playerBalance($this->player))->toBe(2300.0);
});

it('does not let one player cancel another\'s request', function () {
    $asked = askForCash($this, 500)->json('data');
    $other = Customer::factory()->create();

    $this->postJson('/api/v1/customers/'.encryptId($other->id)."/shop-withdrawals/{$asked['withdrawal_id']}/cancel", [], $this->client)->assertNotFound();
});

// expiry

it('expires uncollected requests and gives the money back', function () {
    askForCash($this, 1000)->assertCreated();

    $this->travel(23)->hours();
    $this->artisan('shop-withdrawals:expire')->expectsOutput('Expired 0 shop withdrawal(s).')->assertSuccessful();

    $this->travel(2)->hours();
    $this->artisan('shop-withdrawals:expire')->expectsOutput('Expired 1 shop withdrawal(s).')->assertSuccessful();

    expect(ShopWithdrawal::sole())->toMatchArray(['status' => 'expired', 'closed_by' => 'system:expiry'])
        ->and(playerBalance($this->player))->toBe(3000.0);
});

it('refuses to pay an expired request even before the expiry job ran', function () {
    $asked = askForCash($this, 1000)->json('data');
    $this->travel(25)->hours();

    payCash($this, $asked['withdrawal_id'], $asked['pickup_code'], 'too-late')->assertConflict()->assertJsonPath('code', 'expired');

    expect(ShopWithdrawal::sole()->status)->toBe('expired')
        ->and(playerBalance($this->player))->toBe(3000.0)
        ->and(shopFloatBalance($this->shop))->toBe(1000.0);
});

// elsewhere

it('stops the customer being deleted while a shop withdrawal is pending', function () {
    Wallet::where('customer_id', $this->player->id)->update(['balance' => 500]);
    askForCash($this, 500)->assertCreated();

    expect(app(CustomerService::class)->openObligations($this->player->fresh()))->toBe(['pending_shop_withdrawal']);
});

it('shows shop withdrawals in the player\'s history, but not released ones', function () {
    $paid = askForCash($this, 700)->json('data');
    payCash($this, $paid['withdrawal_id'], $paid['pickup_code'], 'hist-pay')->assertOk();
    askForCash($this, 200)->assertCreated();
    $cancelled = askForCash($this, 100)->json('data');
    $this->postJson('/api/v1/customers/'.encryptId($this->player->id)."/shop-withdrawals/{$cancelled['withdrawal_id']}/cancel", [], $this->client)->assertOk();

    $history = $this->postJson('/api/v1/customers/transactions/'.encryptId($this->player->id), ['payment_type' => 'shop_withdrawal'], $this->client)->assertOk()->json();

    expect(collect($history['transactions'])->pluck('amount')->sort()->values()->all())->toBe(['200.00', '700.00'])
        ->and((float) $history['total'])->toBe(900.0);
});

it('lets GMS see every shop\'s withdrawals with the player', function () {
    askForCash($this, 500)->assertCreated();

    $item = $this->getJson('/api/v1/bet-shops/withdrawals?status=pending', $this->client)->assertOk()->json('data.items.0');
    expect($item)->toMatchArray(['customer_id' => $this->player->id, 'customer_name' => 'Grace Wanjiku', 'bet_shop_id' => $this->shop->id]);

    $this->getJson('/api/v1/bet-shops/'.encryptId($this->shop->id).'/withdrawals', $this->client)->assertOk()->assertJsonPath('data.pagination.total', 1);
});

it('keeps the ledger balanced and counts held withdrawals as owed', function () {
    askForCash($this, 600)->assertCreated();
    $paid = askForCash($this, 700)->json('data');
    payCash($this, $paid['withdrawal_id'], $paid['pickup_code'], 'fin-pay')->assertOk();
    $rejected = askForCash($this, 100)->json('data');
    $this->postJson("/api/v1/shop/withdrawals/{$rejected['withdrawal_id']}/reject", ['reason' => 'No cash'], shopHeaders($this->key, 'fin-reject'))->assertOk();

    expect($this->getJson('/api/v1/finance/trial-balance', $this->client)->assertOk()->json('data.check.balanced'))->toBeTrue();

    $checks = collect($this->getJson('/api/v1/finance/reconciliation', $this->client)->assertOk()->json('data.checks'))->keyBy('key');
    foreach (['customer_wallet_drift', 'bet_shop_float_drift', 'shop_withdrawal_drift', 'ledger_arithmetic', 'unclassified_entries', 'stale_shop_withdrawals', 'held_on_closed_shop_withdrawals', 'negative_balances'] as $check) {
        expect($checks[$check]['status'])->toBe('pass', $check);
    }

    $liabilities = $this->getJson('/api/v1/finance/balance-sheet', $this->client)->assertOk()->json('data.liabilities');
    expect($liabilities['shop_withdrawals'])->toEqual(600)
        ->and($liabilities['bet_shop_floats'])->toEqual(1700)
        ->and($liabilities['customer_wallets'])->toEqual(1700);
});
