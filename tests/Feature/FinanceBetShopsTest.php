<?php

use App\Models\BetShop;
use App\Models\BetShopFloatRedemption;
use App\Models\BetShopLoad;
use App\Models\BetShopWallet;
use App\Models\CompetitionWallet;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\GameWallet;
use App\Models\Wallet;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse('2026-10-16 12:00:00', config('app.timezone')));
    config([
        'finance.test_customer_ids' => [],
        'finance.excise_duty.enabled' => true,
        'finance.excise_duty.rate' => 0.05,
        'finance.excise_duty.effective_from' => null,
    ]);

    $this->gms = apiHeaders(createApiKey('test-finance-bet-shops-key')->key);
    $house = Customer::factory()->create(['id' => 1]);
    Wallet::forceCreate(['id' => 1, 'customer_id' => $house->id, 'balance' => 0]);

    $this->shop = BetShop::factory()->withFloat()->create(['shop_no' => 'S100', 'name' => 'Kariobangi']);
    $this->otherShop = BetShop::factory()->withFloat()->create(['shop_no' => 'S200', 'name' => 'Umoja']);
    $this->key = createShopKey($this->shop);

    $this->alice = Customer::factory()->create(['name' => 'Alice Wanjiru', 'status' => 1]);
    $this->bob = Customer::factory()->create(['name' => 'Bob Otieno', 'status' => 1]);
    $this->carol = Customer::factory()->create(['name' => 'Carol Akinyi', 'status' => 1]);
    Wallet::factory()->create(['customer_id' => $this->carol->id, 'balance' => 1000]);
});

function shopFinance(object $test, string $path, string $query = ''): array
{
    Cache::flush();

    return $test->getJson("/api/v1/finance/{$path}?from=2026-10-16&to=2026-10-16{$query}", $test->gms)->assertOk()->json('data');
}

function gameWithShopStakes(object $test, string $gameId, array $stakes, ?Customer $winner): GameWallet
{
    $game = GameWallet::create(['game_id' => $gameId, 'game_type' => 1, 'balance' => 0, 'status' => 1]);

    foreach ($stakes as [$player, $amount, $shopNo]) {
        $test->postJson('/api/v1/game/bets', array_filter([
            'game_wallet_id' => $game->id, 'customer_id' => $player->id, 'payment_type' => 'deposit', 'amount' => $amount, 'bet_shop_no' => $shopNo,
        ]), $test->gms)->assertCreated();
    }

    if ($winner) {
        $test->postJson('/api/v1/game/withdraw/'.encryptId($game->id), ['customer_id' => (string) $winner->id], $test->gms)->assertCreated();
    }

    return $game;
}

/**
 * A day at shop S100: float bought, loads (one reversed), play attributed to it, cash paid out and pending,
 * and float paid back to the owner.
 */
function shopDay(object $test): void
{
    $deposit = Deposit::create(['trans_id' => 'SFP1', 'trans_time' => '20261016090000', 'trans_amount' => 10000, 'bill_ref_no' => 'SHOP-S100', 'status' => Deposit::STATUS_UNMATCHED]);
    $test->postJson('/api/v1/deposits/'.encryptId($deposit->id).'/shop-float', ['shop_no' => 'S100', 'note' => 'Checked statement'], $test->gms)->assertOk();

    $load = fn (Customer $player, int $amount, string $key) => $test->postJson('/api/v1/shop/loads', ['player_no' => $player->player_no, 'amount' => $amount], shopHeaders($test->key, $key))->assertCreated();
    $load($test->alice, 1000, 'load-alice');
    $load($test->bob, 600, 'load-bob');
    $test->postJson('/api/v1/bet-shops/loads/'.encryptId(BetShopLoad::where('idempotency_key', 'load-bob')->sole()->id).'/reverse', ['reason' => 'Wrong player'], $test->gms)->assertOk();

    gameWithShopStakes($test, 'G-WON', [[$test->alice, 100, 'S100'], [$test->carol, 100, null]], $test->alice);
    gameWithShopStakes($test, 'G-LOST', [[$test->alice, 50, 'S100'], [$test->carol, 50, null]], $test->carol);
    gameWithShopStakes($test, 'G-OPEN', [[$test->alice, 30, 'S100']], null);

    $competition = CompetitionWallet::create([
        'competition_id' => 'TOURN-1', 'cmp_uid' => '700', 'game_type' => 1, 'customer_id' => $test->alice->id,
        'level' => 1, 'jp_rounds' => 3, 'balance' => 0, 'status' => 1,
    ]);
    $test->postJson('/api/v1/competition/transactions', [
        'competition_wallet_id' => $competition->id, 'customer_id' => $test->alice->id, 'payment_type' => 'deposit', 'amount' => 50, 'bet_shop_no' => 'S100',
    ], $test->gms)->assertCreated();

    $ask = fn (int $amount) => $test->postJson('/api/v1/customers/'.encryptId($test->alice->id).'/shop-withdrawals', ['shop_no' => 'S100', 'amount' => $amount], $test->gms)->assertCreated()->json('data');
    $paid = $ask(200);
    $test->postJson("/api/v1/shop/withdrawals/{$paid['withdrawal_id']}/pay", ['pickup_code' => $paid['pickup_code']], shopHeaders($test->key, 'pay-alice'))->assertOk();
    $ask(100);

    $test->postJson('/api/v1/bet-shops/'.encryptId($test->shop->id).'/float-redemptions', ['amount' => 3000, 'payment_reference' => 'UJK8B2C001', 'note' => 'Owner asked for float back'], $test->gms)->assertCreated();
}

// float redemption

it('records float paid back to the owner and refuses more than the float holds', function () {
    BetShopWallet::where('bet_shop_id', $this->shop->id)->update(['balance' => 5000]);
    $url = '/api/v1/bet-shops/'.encryptId($this->shop->id).'/float-redemptions';

    $this->postJson($url, ['amount' => 5000.01, 'payment_reference' => 'X1', 'note' => 'Too much'], $this->gms)
        ->assertConflict()->assertJsonPath('code', 'insufficient_float')->assertJsonPath('float_balance', 5000);

    $this->postJson($url, ['amount' => 4000, 'payment_reference' => 'UJK8B2C001', 'note' => 'Owner closing'], $this->gms)
        ->assertCreated()
        ->assertJsonPath('float_balance', 1000)
        ->assertJsonPath('data.payment_reference', 'UJK8B2C001')
        ->assertJsonPath('data.status', 'active');

    $this->getJson($url, $this->gms)->assertOk()->assertJsonPath('data.pagination.total', 1);
});

it('voids a float redemption, putting the amount back', function () {
    BetShopWallet::where('bet_shop_id', $this->shop->id)->update(['balance' => 1000]);
    $this->postJson('/api/v1/bet-shops/'.encryptId($this->shop->id).'/float-redemptions', ['amount' => 1000, 'payment_reference' => 'R1', 'note' => 'Paid back'], $this->gms)->assertCreated();
    $redemption = BetShopFloatRedemption::sole();

    $this->postJson('/api/v1/bet-shops/float-redemptions/'.encryptId($redemption->id).'/void', ['reason' => 'Payment failed'], $this->gms)
        ->assertOk()->assertJsonPath('data.status', 'voided');
    $this->postJson('/api/v1/bet-shops/float-redemptions/'.encryptId($redemption->id).'/void', ['reason' => 'Again'], $this->gms)
        ->assertConflict()->assertJsonPath('code', 'already_resolved');

    expect((float) BetShopWallet::where('bet_shop_id', $this->shop->id)->value('balance'))->toBe(1000.0);
});

it('lets a shop with float be closed once it is redeemed', function () {
    BetShopWallet::where('bet_shop_id', $this->shop->id)->update(['balance' => 700]);
    $this->postJson('/api/v1/bet-shops/'.encryptId($this->shop->id).'/float-redemptions', ['amount' => 700, 'payment_reference' => 'R2', 'note' => 'Closing'], $this->gms)->assertCreated();

    $this->postJson('/api/v1/bet-shops/'.encryptId($this->shop->id).'/close', ['reason' => 'Owner retired'], $this->gms)
        ->assertOk()->assertJsonPath('data.status', 'closed');
});

// per-shop report

it('reports each shop\'s float, loads, cash withdrawals, play and GGR', function () {
    shopDay($this);

    $data = shopFinance($this, 'bet-shops');
    $s100 = collect($data['items'])->firstWhere('shop_no', 'S100');
    $s200 = collect($data['items'])->firstWhere('shop_no', 'S200');

    expect($s100)->toMatchArray([
        'name' => 'Kariobangi', 'status' => 'active',
        'float_balance' => 6200, 'float_purchased' => 10000, 'float_redeemed' => 3000,
        'loads' => 1, 'loads_gross' => 1000, 'loads_excise' => 50, 'loads_net' => 950, 'loads_reversed' => 1,
        'cash_outs' => 1, 'cash_out_amount' => 200, 'withdrawals_pending' => 1, 'withdrawals_pending_amount' => 100,
        // stakes 100 (won 190) + 50 (lost) + 30 (open, left out)
        'game_stakes' => 180, 'game_stakes_open' => 30, 'game_winnings' => 190, 'game_ggr' => -40,
        // entry 50, house cut 5, 45 still in the open competition wallet
        'competition_entries' => 50, 'competition_prizes' => 0, 'competition_held' => 45, 'competition_ggr' => 5,
        'ggr' => -35,
    ]);
    expect($s200)->toMatchArray(['float_balance' => 0, 'loads' => 0, 'game_stakes' => 0, 'ggr' => 0]);

    expect($data['summary'])->toMatchArray(['shops' => 2, 'float_balance' => 6200, 'loads_gross' => 1000, 'ggr' => -35]);
    expect(shopFinance($this, 'bet-shops', '&status=suspended')['items'])->toBe([]);
});

it('only counts activity inside the range', function () {
    shopDay($this);
    $this->travel(1)->days();

    $s100 = collect(shopFinance($this, 'bet-shops')['items'])->firstWhere('shop_no', 'S100');
    expect($s100)->toMatchArray(['loads' => 1, 'float_purchased' => 10000]);

    $nextDay = collect($this->getJson('/api/v1/finance/bet-shops?from=2026-10-17&to=2026-10-17', $this->gms)->assertOk()->json('data.items'))->firstWhere('shop_no', 'S100');
    expect($nextDay)->toMatchArray(['loads' => 0, 'float_purchased' => 0, 'game_stakes' => 0, 'float_balance' => 6200, 'withdrawals_pending' => 1]);
});

it('lists loads and withdrawals for the range with their totals', function () {
    shopDay($this);

    $loads = shopFinance($this, 'bet-shops/loads');
    expect($loads['summary'])->toEqual([
        'completed' => ['loads' => 1, 'gross_amount' => 1000.0, 'excise_amount' => 50.0, 'net_amount' => 950.0],
        'reversed' => ['loads' => 1, 'gross_amount' => 600.0, 'excise_amount' => 30.0, 'net_amount' => 570.0],
    ])->and(collect($loads['items'])->firstWhere('status', 'completed'))->toMatchArray([
        'bet_shop_id' => $this->shop->id, 'shop_no' => 'S100', 'shop_name' => 'Kariobangi', 'customer_name' => 'Alice Wanjiru', 'player_no' => $this->alice->player_no, 'gross_amount' => 1000,
    ]);
    expect(shopFinance($this, 'bet-shops/loads', '&status=reversed')['pagination']['total'])->toBe(1)
        ->and(shopFinance($this, 'bet-shops/loads', '&bet_shop_id='.$this->otherShop->id)['pagination']['total'])->toBe(0);

    $withdrawals = shopFinance($this, 'bet-shops/withdrawals');
    expect($withdrawals['summary'])->toMatchArray([
        'paid' => ['withdrawals' => 1, 'amount' => 200.0],
        'pending' => ['withdrawals' => 1, 'amount' => 100.0],
        'expired' => ['withdrawals' => 0, 'amount' => 0.0],
    ])->and($withdrawals['items'])->toHaveCount(2);
});

it('counts shop cash in each customer\'s deposited and withdrawn', function () {
    shopDay($this);

    $alice = collect(shopFinance($this, 'customers/top', '&limit=10')['items'])->firstWhere('customer_id', $this->alice->id);

    expect($alice)->toMatchArray([
        'deposited' => 1000, 'shop_loaded' => 1000, 'excise_paid' => 50,
        'withdrawn' => 300, 'shop_withdrawn' => 300,
    ]);
});

it('nets a reversed shop load out of both deposited and excise paid', function () {
    shopDay($this);

    $bob = collect(shopFinance($this, 'customers/top', '&limit=10')['items'])->firstWhere('customer_id', $this->bob->id);

    expect($bob)->toMatchArray(['deposited' => 0, 'shop_loaded' => 0, 'excise_paid' => 0]);
});

it('shows float paid back and counter cash in the cash flow', function () {
    shopDay($this);

    $totals = shopFinance($this, 'cash-flow')['totals'];

    expect($totals['cash_in'])->toMatchArray(['shop_float' => 10000, 'total' => 10000])
        ->and($totals['shop_float'])->toEqual(['redeemed' => 3000])
        ->and($totals['shop_cash'])->toEqual(['loaded' => 1000, 'paid_out' => 200])
        ->and($totals['net_cash'])->toEqual(7000);
});

it('keeps the ledger balanced and reconciliation clean through a shop day', function () {
    shopDay($this);

    expect(shopFinance($this, 'trial-balance')['check']['balanced'])->toBeTrue();

    $checks = collect(shopFinance($this, 'reconciliation')['checks'])->keyBy('key');
    foreach (['customer_wallet_drift', 'bet_shop_float_drift', 'shop_withdrawal_drift', 'game_wallet_drift', 'ledger_arithmetic', 'unclassified_entries', 'shop_float_ledger', 'shop_loads_ledger', 'negative_balances'] as $check) {
        expect($checks[$check]['status'])->toBe('pass', $check);
    }
});

it('exports the shop reports as CSV', function (string $report, string $firstColumns) {
    shopDay($this);

    $csv = $this->get("/api/v1/finance/export/{$report}?from=2026-10-16&to=2026-10-16", $this->gms)->assertOk()->streamedContent();
    $lines = explode("\n", trim(ltrim($csv, "\xEF\xBB\xBF")));

    expect($lines[0])->toStartWith($firstColumns)->and(count($lines))->toBeGreaterThan(1);
})->with([
    'shops' => ['bet-shops', 'bet_shop_id,shop_no,name,status,float_balance,float_purchased,float_redeemed,loads,'],
    'loads' => ['bet-shop-loads', 'id,load_id,bet_shop_id,shop_no,shop_name,customer_id,customer_name,player_no,gross_amount'],
    'withdrawals' => ['bet-shop-withdrawals', 'id,withdrawal_id,bet_shop_id,shop_no,shop_name,customer_id,customer_name,player_no,amount,status'],
]);
