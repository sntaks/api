<?php

use App\Models\ApiKey;
use App\Models\BetShop;
use App\Models\BetShopLoad;
use App\Models\BetShopWallet;
use App\Models\CompetitionWallet;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\GameWallet;
use App\Models\IdempotencyKey;
use App\Models\RequestLog;
use App\Models\Wallet;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    config([
        'finance.test_customer_ids' => [],
        'finance.excise_duty.enabled' => true,
        'finance.excise_duty.rate' => 0.05,
        'finance.excise_duty.effective_from' => null,
    ]);

    $this->gmsKey = createApiKey('test-gaps-gms-key');
    $this->gms = apiHeaders($this->gmsKey->key);

    $house = Customer::factory()->create(['id' => 1]);
    Wallet::forceCreate(['id' => 1, 'customer_id' => $house->id, 'balance' => 0]);

    $this->shop = BetShop::factory()->withFloat(20000)->create(['shop_no' => 'S100', 'name' => 'Kariobangi']);
    $this->otherShop = BetShop::factory()->withFloat(5000)->create(['shop_no' => 'S200', 'name' => 'Umoja']);
    $this->shopKey = createShopKey($this->shop);
    $this->player = Customer::factory()->create(['name' => 'Grace Wanjiku', 'status' => 1, 'id_no' => '12345678']);
});

function gapLoad(object $test, int $amount, string $key, ?ApiKey $shopKey = null)
{
    return $test->postJson('/api/v1/shop/loads', ['player_no' => $test->player->player_no, 'amount' => $amount], shopHeaders($shopKey ?? $test->shopKey, $key))->assertCreated();
}

// 1. key issuing

it('issues a new key every time and stores no idempotency record for it', function () {
    $url = '/api/v1/bet-shops/'.encryptId($this->shop->id).'/keys';

    $first = $this->postJson($url, ['name' => 'Desk 1'], $this->gms)->assertCreated()->json('data.key');
    $second = $this->postJson($url, ['name' => 'Desk 1'], $this->gms)->assertCreated()->json('data.key');

    expect($second)->not->toBe($first)
        ->and(IdempotencyKey::count())->toBe(0)
        ->and(RequestLog::query()->get()->toJson())->not->toContain($first);
});

// 2. customer totals

it('totals a customer\'s shop loads (net, not reversed) and paid shop withdrawals', function () {
    gapLoad($this, 1000, 'cust-1');
    gapLoad($this, 600, 'cust-2');
    $this->postJson('/api/v1/bet-shops/loads/'.encryptId(BetShopLoad::where('idempotency_key', 'cust-2')->sole()->id).'/reverse', ['reason' => 'Wrong player'], $this->gms)->assertOk();

    $paid = $this->postJson('/api/v1/customers/'.encryptId($this->player->id).'/shop-withdrawals', ['shop_no' => 'S100', 'amount' => 200], $this->gms)->json('data');
    $this->postJson("/api/v1/shop/withdrawals/{$paid['withdrawal_id']}/pay", ['pickup_code' => $paid['pickup_code']], shopHeaders($this->shopKey, 'cust-pay'))->assertOk();
    $this->postJson('/api/v1/customers/'.encryptId($this->player->id).'/shop-withdrawals', ['shop_no' => 'S100', 'amount' => 100], $this->gms)->assertCreated();

    $this->getJson('/api/v1/customers/'.encryptId($this->player->id), $this->gms)->assertOk()
        ->assertJsonPath('data.shop_loads', 950)
        ->assertJsonPath('data.shop_withdrawals', 200)
        ->assertJsonPath('data.player_no', $this->player->player_no);
});

// 3 and 4. finance shop filter and shop columns

it('reports one shop when bet_shop_id is sent, and returns the shop on finance rows', function () {
    gapLoad($this, 1000, 'fin-1');
    gapLoad($this, 500, 'fin-2', createShopKey($this->otherShop));

    $data = $this->getJson('/api/v1/finance/bet-shops?bet_shop_id='.$this->shop->id, $this->gms)->assertOk()->json('data');
    expect($data['items'])->toHaveCount(1)
        ->and($data['items'][0]['shop_no'])->toBe('S100')
        ->and($data['summary'])->toMatchArray(['shops' => 1, 'loads_gross' => 1000]);

    $csv = $this->get('/api/v1/finance/export/bet-shops?bet_shop_id='.$this->otherShop->id, $this->gms)->assertOk()->streamedContent();
    expect(count(explode("\n", trim($csv))))->toBe(2);

    $row = $this->getJson('/api/v1/finance/bet-shops/loads?bet_shop_id='.$this->shop->id, $this->gms)->assertOk()->json('data.items.0');
    expect($row)->toMatchArray(['bet_shop_id' => $this->shop->id, 'shop_no' => 'S100', 'shop_name' => 'Kariobangi', 'player_no' => $this->player->player_no]);
});

// 5. float_balance on a refused void

it('says how much float is left when a float purchase cannot be voided', function () {
    $deposit = Deposit::create(['trans_id' => 'SFP-GAP', 'trans_time' => '20261016090000', 'trans_amount' => 3000, 'bill_ref_no' => 'SHOP-S200', 'status' => Deposit::STATUS_UNMATCHED]);
    $credited = $this->postJson('/api/v1/deposits/'.encryptId($deposit->id).'/shop-float', ['shop_no' => 'S200', 'note' => 'Checked'], $this->gms)->assertOk()->json('data');
    BetShopWallet::where('bet_shop_id', $this->otherShop->id)->update(['balance' => 2500]);

    $this->postJson('/api/v1/bet-shops/float-purchases/'.encryptId($credited['float_purchase']['id']).'/void', ['reason' => 'Wrong shop'], $this->gms)
        ->assertConflict()
        ->assertJson(['success' => false, 'code' => 'insufficient_float', 'float_balance' => 2500]);
});

// 6. the shop_float resolution

it('shows the shop and float purchase on a shop_float resolution', function () {
    $deposit = Deposit::create(['trans_id' => 'SFP-RES', 'trans_time' => '20261016090000', 'trans_amount' => 1000, 'bill_ref_no' => 'SHOP-S100', 'status' => Deposit::STATUS_UNMATCHED]);

    $this->getJson('/api/v1/deposits/unmatched', $this->gms)->assertOk()
        ->assertJsonPath('data.items.0.bet_shop', ['id' => $this->shop->id, 'shop_no' => 'S100', 'name' => 'Kariobangi', 'status' => 'active']);

    $purchaseId = $this->postJson('/api/v1/deposits/'.encryptId($deposit->id).'/shop-float', ['shop_no' => 'S100', 'note' => 'Checked statement'], $this->gms)
        ->assertOk()->json('data.float_purchase.id');

    $resolution = $this->getJson('/api/v1/deposits/unmatched?status=shop_float', $this->gms)->assertOk()->json('data.items.0.resolution');
    expect($resolution)->toMatchArray([
        'action' => 'shop_float', 'float_purchase_id' => $purchaseId, 'bet_shop_id' => $this->shop->id,
        'shop_no' => 'S100', 'shop_name' => 'Kariobangi', 'note' => 'Checked statement', 'resolved_by' => 'api_key:'.$this->gmsKey->id,
    ])->and($resolution['resolved_at'])->not->toBeNull();
});

// 7. unknown shop vs unknown deposit

it('tells an unknown shop number apart from a missing deposit', function () {
    $deposit = Deposit::create(['trans_id' => 'SFP-404', 'trans_time' => '20261016090000', 'trans_amount' => 500, 'bill_ref_no' => 'SHOP-S100', 'status' => Deposit::STATUS_UNMATCHED]);

    $this->postJson('/api/v1/deposits/'.encryptId($deposit->id).'/shop-float', ['shop_no' => 'S999', 'note' => 'Checked'], $this->gms)
        ->assertUnprocessable()->assertJsonValidationErrors(['shop_no' => 'No bet shop has that number.']);

    $this->postJson('/api/v1/deposits/'.encryptId(999999).'/shop-float', ['shop_no' => 'S100', 'note' => 'Checked'], $this->gms)
        ->assertNotFound()->assertJsonPath('code', 'deposit_not_found');
});

// 8. rate limits per key, and the multiplier

it('gives each API key its own rate-limit budget, even from the same IP', function () {
    $other = createApiKey('test-gaps-other-key');

    foreach (range(1, 20) as $ignored) {
        $this->getJson('/api/v1/stats/customers', $this->gms)->assertOk();
    }

    $this->getJson('/api/v1/stats/customers', $this->gms)->assertStatus(429);
    $this->getJson('/api/v1/stats/customers', apiHeaders($other->key))->assertOk();
});

it('multiplies a key\'s limits by its rate_limit_multiplier', function () {
    createApiKey('test-gaps-same-name-key');
    $this->artisan('apikey:rate-limit', ['key' => 'Test API Key', 'multiplier' => 2])
        ->expectsOutput('More than one key has that name; use its id.')
        ->assertFailed();
    $this->artisan('apikey:rate-limit', ['key' => (string) $this->gmsKey->id, 'multiplier' => 2])
        ->expectsOutput("API key #{$this->gmsKey->id} (Test API Key) now gets 2x the standard rate limits.")
        ->assertSuccessful();
    $this->artisan('apikey:rate-limit', ['key' => (string) $this->gmsKey->id, 'multiplier' => 21])->assertFailed();

    foreach (range(1, 40) as $ignored) {
        $this->getJson('/api/v1/stats/customers', $this->gms)->assertOk();
    }

    $this->getJson('/api/v1/stats/customers', $this->gms)->assertStatus(429);
});

it('limits made-up keys by IP, so guessing keys does not reset the budget', function () {
    foreach (range(1, 20) as $attempt) {
        $this->getJson('/api/v1/stats/customers', apiHeaders("made-up-key-{$attempt}"))->assertUnauthorized();
    }

    $this->getJson('/api/v1/stats/customers', apiHeaders('made-up-key-21'))->assertStatus(429);
    $this->getJson('/api/v1/stats/customers', $this->gms)->assertOk();
});

afterEach(function () {
    RateLimiter::clear('stats');
});

// 9. player_no is the bare 8 digits everywhere

it('returns player_no as the bare 8 digits on shop endpoints', function () {
    $this->getJson('/api/v1/shop/players/'.substr($this->player->player_no, 0, 4).' '.substr($this->player->player_no, 4), apiHeaders($this->shopKey->key))
        ->assertOk()->assertJsonPath('data.player_no', $this->player->player_no);

    gapLoad($this, 500, 'raw-1')->assertJsonPath('data.player.player_no', $this->player->player_no);
    $this->getJson('/api/v1/bet-shops/loads', $this->gms)->assertOk()->assertJsonPath('data.items.0.player.player_no', $this->player->player_no);
});

// 10. bet_shop_no on stakes

it('returns the shop number on stakes made at a shop', function () {
    Wallet::factory()->create(['customer_id' => $this->player->id, 'balance' => 500]);
    $game = GameWallet::create(['game_id' => 'G-GAP', 'game_type' => 1, 'balance' => 0, 'status' => 1]);
    $this->postJson('/api/v1/game/bets', ['game_wallet_id' => $game->id, 'customer_id' => $this->player->id, 'payment_type' => 'deposit', 'amount' => 100, 'bet_shop_no' => 'S100'], $this->gms)->assertCreated();

    $competition = CompetitionWallet::create(['competition_id' => 'T-1', 'cmp_uid' => '901', 'game_type' => 1, 'customer_id' => $this->player->id, 'level' => 1, 'jp_rounds' => 3, 'balance' => 0, 'status' => 1]);
    $this->postJson('/api/v1/competition/transactions', ['competition_wallet_id' => $competition->id, 'customer_id' => $this->player->id, 'payment_type' => 'deposit', 'amount' => 50, 'bet_shop_no' => 'S200'], $this->gms)->assertCreated();

    $this->getJson('/api/v1/game/bets', $this->gms)->assertOk()->assertJsonPath('data.0.bet_shop_no', 'S100');
    $this->getJson('/api/v1/competition/transactions', $this->gms)->assertOk()->assertJsonPath('data.0.bet_shop_no', 'S200');
});

// 12. sort top customers by shop cash

it('sorts top customers by shop cash', function () {
    $other = Customer::factory()->create(['status' => 1]);
    Wallet::factory()->create(['customer_id' => $other->id, 'balance' => 0]);
    $this->ledgerDeposit = app(LedgerService::class);
    $this->ledgerDeposit->recordDeposit(Deposit::create(['trans_id' => 'MP1', 'trans_time' => '20261016090000', 'trans_amount' => 5000, 'status' => 2]), Wallet::where('customer_id', $other->id)->sole(), 5000.0);
    gapLoad($this, 1000, 'sort-1');

    $byDeposit = $this->getJson('/api/v1/finance/customers/top', $this->gms)->assertOk()->json('data.items');
    $byShop = $this->getJson('/api/v1/finance/customers/top?sort=shop_loaded', $this->gms)->assertOk()->json('data.items');

    expect($byDeposit[0]['customer_id'])->toBe($other->id)
        ->and($byShop[0]['customer_id'])->toBe($this->player->id)
        ->and($byShop[0])->toMatchArray(['shop_loaded' => 1000, 'deposited' => 1000]);
    $this->getJson('/api/v1/finance/customers/top?sort=shop_withdrawn', $this->gms)->assertOk();
});

// 13. one load

it('opens a single load', function () {
    $loadId = gapLoad($this, 700, 'one-1')->json('data.id');

    $this->getJson('/api/v1/bet-shops/loads/'.encryptId($loadId), $this->gms)->assertOk()
        ->assertJson(['data' => ['id' => $loadId, 'gross_amount' => 700, 'customer_id' => $this->player->id, 'bet_shop_id' => $this->shop->id]]);
    $this->getJson('/api/v1/bet-shops/loads/'.encryptId(999999), $this->gms)->assertNotFound();
});

// 14. withdrawal filters

it('filters operations withdrawals by customer and day', function () {
    Wallet::factory()->create(['customer_id' => $this->player->id, 'balance' => 1000]);
    $other = Customer::factory()->create(['status' => 1, 'id_no' => '87654321']);
    Wallet::factory()->create(['customer_id' => $other->id, 'balance' => 1000]);

    $this->postJson('/api/v1/customers/'.encryptId($this->player->id).'/shop-withdrawals', ['shop_no' => 'S100', 'amount' => 100], $this->gms)->assertCreated();
    $this->travel(1)->days();
    $this->postJson('/api/v1/customers/'.encryptId($other->id).'/shop-withdrawals', ['shop_no' => 'S100', 'amount' => 200], $this->gms)->assertCreated();

    $total = fn (string $query) => $this->getJson("/api/v1/bet-shops/withdrawals{$query}", $this->gms)->assertOk()->json('data.pagination.total');

    expect($total('?customer_id='.$this->player->id))->toBe(1)
        ->and($total('?from='.now()->toDateString().'&to='.now()->toDateString()))->toBe(1)
        ->and($total(''))->toBe(2);
    $this->getJson('/api/v1/bet-shops/withdrawals?from=2026-10-20&to=2026-10-01', $this->gms)->assertUnprocessable();
});
