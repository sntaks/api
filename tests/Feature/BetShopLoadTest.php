<?php

use App\Models\BetShop;
use App\Models\BetShopLoad;
use App\Models\BetShopWallet;
use App\Models\Customer;
use App\Models\ExciseDutyCharge;
use App\Models\ExciseDutyRemittance;
use App\Models\LedgerEntry;
use App\Models\Referral;
use App\Models\ReferralBonus;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Services\BetShopLoadService;
use App\Util\PlayerNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    config([
        'finance.excise_duty.enabled' => true,
        'finance.excise_duty.rate' => 0.05,
        'finance.excise_duty.effective_from' => null,
        'finance.test_customer_ids' => [],
    ]);

    $this->gms = apiHeaders(createApiKey('test-shop-loads-gms-key')->key);
    $this->shop = BetShop::factory()->withFloat(30000)->create(['shop_no' => 'S100']);
    $this->key = createShopKey($this->shop);
    $this->player = Customer::factory()->create(['name' => 'Grace Wanjiku Kamau', 'status' => 1]);
});

function shopLoad(object $test, string $idempotencyKey, int $amount = 1000, ?string $playerNo = null, ?object $key = null)
{
    return $test->postJson('/api/v1/shop/loads', [
        'player_no' => $playerNo ?? PlayerNumber::format($test->player->player_no),
        'amount' => $amount,
    ], shopHeaders($key ?? $test->key, $idempotencyKey));
}

function floatOf(BetShop $shop): float
{
    return (float) BetShopWallet::where('bet_shop_id', $shop->id)->value('balance');
}

function walletOf(Customer $customer): float
{
    return (float) (Wallet::where('customer_id', $customer->id)->value('balance') ?? 0);
}

// player lookup

it('finds a player by number and shows only a masked name', function () {
    $this->getJson('/api/v1/shop/players/'.PlayerNumber::format($this->player->player_no), apiHeaders($this->key->key))
        ->assertOk()
        ->assertExactJson([
            'success' => true,
            'message' => 'Player found',
            'data' => ['player_no' => $this->player->player_no, 'name' => 'Grace K****', 'active' => true],
        ]);
});

it('rejects a mistyped player number before looking anyone up', function () {
    $typo = substr_replace($this->player->player_no, (string) (((int) $this->player->player_no[3] + 1) % 10), 3, 1);

    $this->getJson("/api/v1/shop/players/{$typo}", apiHeaders($this->key->key))
        ->assertUnprocessable()->assertJsonPath('code', 'invalid_player_no');
});

it('does not find deleted players, and flags inactive ones', function () {
    $inactive = Customer::factory()->create(['status' => 0]);
    $deleted = Customer::factory()->create();
    $deleted->delete();

    $this->getJson('/api/v1/shop/players/'.$inactive->player_no, apiHeaders($this->key->key))->assertOk()->assertJsonPath('data.active', false);
    $this->getJson('/api/v1/shop/players/'.$deleted->player_no, apiHeaders($this->key->key))->assertNotFound()->assertJsonPath('code', 'player_not_found');
});

// loading

it('needs an Idempotency-Key to load', function () {
    shopLoad($this, '')->assertStatus(400)->assertJsonPath('code', 'idempotency_key_required');

    expect(BetShopLoad::count())->toBe(0);
});

it('loads a player from the shop float and takes excise duty as for a deposit', function () {
    $response = shopLoad($this, 'load-1', 1000)->assertCreated();

    $response->assertJson(['success' => true, 'data' => [
        'shop_no' => 'S100', 'gross_amount' => 1000, 'excise_amount' => 50, 'net_amount' => 950, 'status' => 'completed',
        'float_balance' => 29000, 'player' => ['name' => 'Grace K****'],
    ]]);

    $load = BetShopLoad::sole();
    expect(floatOf($this->shop))->toBe(29000.0)
        ->and(walletOf($this->player))->toBe(950.0)
        ->and($load->idempotency_key)->toBe('load-1')
        ->and($load->loaded_by)->toBe('api_key:'.$this->key->id);

    $entries = LedgerEntry::orderBy('id')->get();
    expect($entries->pluck('entry_type')->all())->toBe(['shop_load', 'shop_load', 'excise_duty'])
        ->and($entries[0])->toMatchArray(['wallet_type' => 'bet_shop', 'debit' => '1000.00'])
        ->and($entries[1])->toMatchArray(['wallet_type' => 'wallet', 'credit' => '1000.00', 'customer_id' => $this->player->id])
        ->and($entries[2]->referenceable_type)->toBe(BetShopLoad::class);

    expect(ExciseDutyCharge::sole())->toMatchArray(['bet_shop_load_id' => $load->id, 'deposit_id' => null, 'excise_amount' => '50.00', 'net_amount' => '950.00'])
        ->and(Transaction::sole())->toMatchArray(['payment_type' => BetShopLoad::class, 'payment_id' => $load->id, 'amount' => '950.00', 'status' => 2]);
});

it('credits the full amount when excise duty is off', function () {
    config(['finance.excise_duty.enabled' => false]);

    shopLoad($this, 'load-no-excise', 500)->assertCreated()->assertJsonPath('data.net_amount', 500);

    expect(walletOf($this->player))->toBe(500.0)->and(ExciseDutyCharge::count())->toBe(0);
});

it('replays a retried load instead of loading twice', function () {
    $first = shopLoad($this, 'load-retry')->assertCreated()->json('data.load_id');
    $again = shopLoad($this, 'load-retry')->assertCreated()->json('data.load_id');

    expect($again)->toBe($first)
        ->and(BetShopLoad::count())->toBe(1)
        ->and(floatOf($this->shop))->toBe(29000.0);
});

it('replays from the database when the same key gets past the response cache', function () {
    $service = app(BetShopLoadService::class);

    $first = $service->load($this->shop, $this->player->player_no, 1000, 'load-race', null);
    $second = $service->load($this->shop, $this->player->player_no, 1000, 'load-race', null);

    expect($first['status_code'])->toBe(201)
        ->and($second)->toMatchArray(['status_code' => 200, 'replayed' => true])
        ->and($second['load']->id)->toBe($first['load']->id)
        ->and(floatOf($this->shop))->toBe(29000.0);
});

it('makes two identical loads with different keys: two real cash payments', function () {
    shopLoad($this, 'cash-1', 200)->assertCreated();
    shopLoad($this, 'cash-2', 200)->assertCreated();

    expect(BetShopLoad::count())->toBe(2)->and(floatOf($this->shop))->toBe(29600.0);
});

it('refuses a load the float cannot cover', function () {
    BetShopWallet::where('bet_shop_id', $this->shop->id)->update(['balance' => 300]);

    shopLoad($this, 'too-big', 500)->assertConflict()
        ->assertJsonPath('code', 'insufficient_float')
        ->assertJsonPath('float_balance', 300);

    expect(BetShopLoad::count())->toBe(0)->and(walletOf($this->player))->toBe(0.0);
});

it('only loads whole shillings within the per-load limits', function (mixed $amount) {
    $this->postJson('/api/v1/shop/loads', ['player_no' => $this->player->player_no, 'amount' => $amount], shopHeaders($this->key, 'bad-'.$amount))
        ->assertUnprocessable()->assertJsonValidationErrors('amount');
})->with([49, 20001, 100.5, 'lots']);

it('caps what one player can be loaded in a day across every shop', function () {
    BetShopWallet::where('bet_shop_id', $this->shop->id)->update(['balance' => 100000]);
    $otherShop = BetShop::factory()->withFloat(30000)->create(['shop_no' => 'S200']);
    $otherKey = createShopKey($otherShop);

    shopLoad($this, 'day-1', 20000)->assertCreated();
    shopLoad($this, 'day-2', 20000, key: $otherKey)->assertCreated();

    shopLoad($this, 'day-3', 20000)->assertUnprocessable()
        ->assertJsonPath('code', 'daily_limit_reached')
        ->assertJsonPath('message', 'The player can be loaded at most KES 50000 a day at shops; KES 10000 is left today');

    shopLoad($this, 'day-4', 10000)->assertCreated();

    $this->travel(1)->days();
    shopLoad($this, 'day-5', 20000)->assertCreated();
});

it('does not count reversed loads towards the daily cap', function () {
    config(['bet_shops.loads.daily_limit_per_player' => 1000]);

    shopLoad($this, 'cap-1', 1000)->assertCreated();
    $this->postJson('/api/v1/bet-shops/loads/'.encryptId(BetShopLoad::sole()->id).'/reverse', ['reason' => 'Wrong player'], $this->gms)->assertOk();

    shopLoad($this, 'cap-2', 1000)->assertCreated();
});

it('refuses to load an inactive player or an unknown number', function () {
    $this->player->update(['status' => 0]);
    shopLoad($this, 'inactive')->assertConflict()->assertJsonPath('code', 'player_inactive');

    $unknown = '1234567'.PlayerNumber::checkDigit('1234567');
    Customer::where('player_no', $unknown)->delete();
    shopLoad($this, 'unknown', playerNo: $unknown)->assertNotFound()->assertJsonPath('code', 'player_not_found');

    expect(BetShopLoad::count())->toBe(0);
});

it('counts the first shop load as the referred player\'s first deposit', function () {
    config(['referrals.enabled' => true, 'referrals.effective_from' => null]);
    $referral = Referral::factory()->verified()->create(['referred_id' => $this->player->id]);

    shopLoad($this, 'first-deposit', 500)->assertCreated();

    $referral->refresh();
    expect($referral->first_bet_shop_load_id)->toBe(BetShopLoad::sole()->id)
        ->and($referral->first_deposit_id)->toBeNull()
        ->and($referral->first_deposited_at)->not->toBeNull()
        ->and(ReferralBonus::where('referral_id', $referral->id)->pluck('milestone')->sort()->values()->all())->toBe(['first_deposit', 'signup']);

    $this->getJson('/api/v1/referrals?status=deposited', $this->gms)->assertOk()->assertJsonPath('data.0.status', 'deposited');
});

it('lists only the shop\'s own loads, with masked players', function () {
    shopLoad($this, 'mine', 300)->assertCreated();
    $otherShop = BetShop::factory()->withFloat(5000)->create();
    shopLoad($this, 'theirs', 400, key: createShopKey($otherShop))->assertCreated();

    $items = $this->getJson('/api/v1/shop/loads', apiHeaders($this->key->key))->assertOk()->json('data.items');

    expect($items)->toHaveCount(1)
        ->and($items[0])->toMatchArray(['gross_amount' => 300, 'shop_no' => 'S100'])
        ->and($items[0])->not->toHaveKey('customer_name');

    $all = $this->getJson('/api/v1/bet-shops/loads', $this->gms)->assertOk()->json('data');
    expect($all['pagination']['total'])->toBe(2)
        ->and(collect($all['items'])->firstWhere('gross_amount', 300)['customer_name'])->toBe('Grace Wanjiku Kamau');
});

it('shows shop loads in the player\'s transaction history, but not reversed ones', function () {
    shopLoad($this, 'history-1', 1000)->assertCreated();
    shopLoad($this, 'history-2', 400)->assertCreated();
    $this->postJson('/api/v1/bet-shops/loads/'.encryptId(BetShopLoad::where('idempotency_key', 'history-2')->sole()->id).'/reverse', ['reason' => 'Wrong amount'], $this->gms)->assertOk();

    foreach (['all', 'shop_load'] as $type) {
        $history = $this->postJson('/api/v1/customers/transactions/'.encryptId($this->player->id), ['payment_type' => $type], $this->gms)->assertOk()->json();

        expect($history['transactions'])->toHaveCount(1, $type)
            ->and($history['transactions'][0]['payment_type'])->toBe(BetShopLoad::class)
            ->and((float) $history['total'])->toBe(950.0);
    }

    $this->postJson('/api/v1/customers/transactions/'.encryptId($this->player->id), ['payment_type' => 'deposit'], $this->gms)
        ->assertOk()->assertJsonCount(0, 'transactions');
});

// reversal

it('lets GMS reverse a load: excise back, wallet back to before, float refunded', function () {
    shopLoad($this, 'to-reverse', 1000)->assertCreated();
    $load = BetShopLoad::sole();

    $this->postJson('/api/v1/bet-shops/loads/'.encryptId($load->id).'/reverse', ['reason' => 'Wrong player'], $this->gms)
        ->assertOk()
        ->assertJsonPath('data.status', 'reversed')
        ->assertJsonPath('data.reversal_reason', 'Wrong player');

    expect(floatOf($this->shop))->toBe(30000.0)
        ->and(walletOf($this->player))->toBe(0.0)
        ->and(ExciseDutyCharge::sole()->status)->toBe(ExciseDutyCharge::STATUS_REVERSED)
        ->and(LedgerEntry::where('entry_type', 'shop_load_reversal')->count())->toBe(2)
        ->and(LedgerEntry::where('entry_type', 'shop_load')->pluck('status')->unique()->all())->toBe(['reversed'])
        ->and(Transaction::sole()->status)->toBe(3);
});

it('does not reverse a load twice', function () {
    shopLoad($this, 'twice', 1000)->assertCreated();
    $load = BetShopLoad::sole();
    $this->postJson('/api/v1/bet-shops/loads/'.encryptId($load->id).'/reverse', ['reason' => 'Wrong player'], $this->gms)->assertOk();

    $this->postJson('/api/v1/bet-shops/loads/'.encryptId($load->id).'/reverse', ['reason' => 'Again'], $this->gms)
        ->assertConflict()->assertJsonPath('code', 'already_reversed');

    expect(floatOf($this->shop))->toBe(30000.0);
});

it('refuses to reverse a load the player has already spent', function () {
    shopLoad($this, 'spent', 1000)->assertCreated();
    Wallet::where('customer_id', $this->player->id)->update(['balance' => 900]);

    $this->postJson('/api/v1/bet-shops/loads/'.encryptId(BetShopLoad::sole()->id).'/reverse', ['reason' => 'Wrong player'], $this->gms)
        ->assertConflict()->assertJsonPath('code', 'player_has_spent');

    expect(floatOf($this->shop))->toBe(29000.0);
});

it('refuses to reverse a load whose excise duty was remitted', function () {
    shopLoad($this, 'remitted', 1000)->assertCreated();
    ExciseDutyCharge::sole()->update(['remittance_id' => ExciseDutyRemittance::factory()->create()->id]);

    $this->postJson('/api/v1/bet-shops/loads/'.encryptId(BetShopLoad::sole()->id).'/reverse', ['reason' => 'Wrong player'], $this->gms)
        ->assertConflict()->assertJsonPath('code', 'excise_remitted');

    expect(walletOf($this->player))->toBe(950.0);
});

it('never lets a shop reverse its own load', function () {
    shopLoad($this, 'shop-reverse', 1000)->assertCreated();

    $this->postJson('/api/v1/bet-shops/loads/'.encryptId(BetShopLoad::sole()->id).'/reverse', ['reason' => 'Oops'], apiHeaders($this->key->key))
        ->assertForbidden();

    expect(BetShopLoad::sole()->status)->toBe('completed');
});

// finance

it('keeps the ledger balanced and reconciliation clean across loads and a reversal', function () {
    shopLoad($this, 'fin-1', 1000)->assertCreated();
    shopLoad($this, 'fin-2', 2500)->assertCreated();
    $this->postJson('/api/v1/bet-shops/loads/'.encryptId(BetShopLoad::where('idempotency_key', 'fin-1')->sole()->id).'/reverse', ['reason' => 'Wrong player'], $this->gms)->assertOk();

    $trial = $this->getJson('/api/v1/finance/trial-balance', $this->gms)->assertOk()->json('data.check');
    expect($trial['balanced'])->toBeTrue();

    $checks = collect($this->getJson('/api/v1/finance/reconciliation', $this->gms)->assertOk()->json('data.checks'))->keyBy('key');
    foreach (['customer_wallet_drift', 'bet_shop_float_drift', 'ledger_arithmetic', 'unclassified_entries', 'shop_loads_ledger', 'excise_duty_amounts', 'excise_duty_ledger', 'negative_balances'] as $check) {
        expect($checks[$check]['status'])->toBe('pass', $check);
    }

    $charges = $this->getJson('/api/v1/finance/excise-duty/charges', $this->gms)->assertOk()->json('data.items');
    expect(collect($charges)->pluck('source')->unique()->all())->toBe(['shop_load']);

    $liabilities = $this->getJson('/api/v1/finance/balance-sheet', $this->gms)->assertOk()->json('data.liabilities');
    expect($liabilities['bet_shop_floats'])->toEqual(27500)
        ->and($liabilities['customer_wallets'])->toEqual(2375);
});
