<?php

use App\Models\CompetitionTransaction;
use App\Models\CompetitionWallet;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\GameWallet;
use App\Models\Purchase;
use App\Models\Wallet;
use App\Models\Withdraw;
use App\Services\LedgerService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00:00', config('app.timezone')));
    config(['finance.test_customer_ids' => [1, 2]]);

    $this->apiKey = createApiKey('test-finance-drilldown-key');
    $this->headers = apiHeaders($this->apiKey->key);
    $this->ledger = app(LedgerService::class);

    $this->house = drillWallet(1, 0);
    $this->testWallet = drillWallet(2, 1000);
    $this->alice = drillWallet(900, 1000, 'Alice Wanjiru', '254712345678');
    $this->bob = drillWallet(901, 1000, 'Bob Otieno', '254798765432');
});

function drillWallet(int $customerId, float $balance, string $name = 'Customer', ?string $phone = null): Wallet
{
    Customer::factory()->create(['id' => $customerId, 'name' => $name, 'phone_no' => $phone ?? '2547000'.str_pad((string) $customerId, 5, '0', STR_PAD_LEFT)]);

    return Wallet::forceCreate(['id' => $customerId, 'customer_id' => $customerId, 'balance' => $balance]);
}

function drillDeposit(string $transId, float $amount, int $status = 2, ?string $msisdn = null, ?string $billRef = null): Deposit
{
    return Deposit::create([
        'trans_id' => $transId, 'trans_time' => '20260920100000', 'trans_amount' => $amount,
        'status' => $status, 'msisdn' => $msisdn, 'bill_ref_no' => $billRef,
    ]);
}

function drillGame(object $test, array $players, ?int $winner, string $gameId): GameWallet
{
    $gameWallet = GameWallet::create(['game_id' => $gameId, 'game_type' => 1, 'balance' => 0]);

    foreach ($players as $player) {
        legacyGameCreditStake($player, $gameWallet, 100);
    }

    if ($winner !== null) {
        $test->postJson('/api/v1/game/withdraw/'.encryptId($gameWallet->id), [
            'customer_id' => (string) $winner,
        ], $test->headers)->assertStatus(201);
    }

    return $gameWallet;
}

function drillCompetition(object $test, Wallet $player, string $cmpUid, int $gameType, int $rounds, float $amount, float $cut): CompetitionWallet
{
    $wallet = CompetitionWallet::create([
        'competition_id' => "COMP-{$cmpUid}", 'cmp_uid' => $cmpUid, 'game_type' => $gameType,
        'customer_id' => $player->customer_id, 'jp_rounds' => $rounds, 'balance' => 0, 'status' => 1,
    ]);
    $transaction = CompetitionTransaction::create([
        'competition_wallet_id' => $wallet->id, 'customer_id' => $player->customer_id,
        'payment_type' => 'deposit', 'amount' => $amount, 'status' => 2,
    ]);

    $test->ledger->recordCompetitionBet($transaction, $player->fresh(), $wallet, $amount, $cut);
    $test->ledger->recordHouseCut($test->house->fresh(), $cut, 'competition_bet', $transaction, ['competition_wallet_id' => $wallet->id]);

    return $wallet;
}

function drillGet(object $test, string $path): array
{
    return $test->getJson("/api/v1/finance/{$path}", $test->headers)->assertOk()->json('data');
}

// deposits

it('lists deposits with masked phone numbers and what each bought', function () {
    $wallet = drillDeposit('DD1', 500, 2, '254712345678', '0712345678');
    $this->ledger->recordDeposit($wallet, $this->alice->fresh(), 500.0);

    $gift = drillDeposit('DD2', 100);
    Purchase::create(['customer_id' => 900, 'deposit_id' => $gift->id, 'purchase_type' => 'gift', 'amount' => 100, 'value' => 0]);

    $data = drillGet($this, 'deposits');

    expect($data['pagination'])->toMatchArray(['page' => 1, 'per_page' => 50, 'total' => 2, 'last_page' => 1]);

    $first = collect($data['items'])->firstWhere('trans_id', 'DD1');
    expect($first)->toMatchArray([
        'amount' => 500, 'kind' => 'wallet_deposit', 'status' => 'processed', 'customer_id' => 900,
        'customer_name' => 'Alice Wanjiru', 'msisdn' => '2547****5678', 'bill_ref_no' => '0712**5678',
    ]);
    expect(collect($data['items'])->firstWhere('trans_id', 'DD2')['kind'])->toBe('gift');
    expect(json_encode($data))->not->toContain('254712345678');
});

it('summarises deposits by kind and status and ranks the top depositors', function () {
    $a = drillDeposit('DS1', 500);
    $this->ledger->recordDeposit($a, $this->alice->fresh(), 500.0);
    $b = drillDeposit('DS2', 300);
    $this->ledger->recordDeposit($b, $this->bob->fresh(), 300.0);
    $c = drillDeposit('DS3', 250);
    $this->ledger->recordDeposit($c, $this->alice->fresh(), 250.0);
    drillDeposit('DS4', 200, 0);

    $summary = drillGet($this, 'deposits')['summary'];

    expect($summary['payments'])->toBe(4);
    expect($summary['amount'])->toEqual(1250);
    expect($summary['by_kind']['wallet_deposit'])->toEqual(['payments' => 3, 'amount' => 1050]);
    expect($summary['by_kind']['unmatched'])->toEqual(['payments' => 1, 'amount' => 200]);
    expect($summary['by_status']['processed']['payments'])->toBe(3);
    expect($summary['by_status']['unmatched']['amount'])->toEqual(200);
    expect(array_column($summary['top_depositors'], 'customer_id'))->toBe([900, 901]);
    expect($summary['top_depositors'][0]['amount'])->toEqual(750);
});

it('filters deposits and leaves out test customers unless asked', function () {
    $mine = drillDeposit('DF1', 100);
    $this->ledger->recordDeposit($mine, $this->alice->fresh(), 100.0);
    $test = drillDeposit('DF2', 60);
    $this->ledger->recordDeposit($test, $this->testWallet->fresh(), 60.0);
    drillDeposit('DF3', 40, 0);

    expect(drillGet($this, 'deposits')['pagination']['total'])->toBe(2);
    expect(drillGet($this, 'deposits?exclude_test=false')['pagination']['total'])->toBe(3);
    expect(drillGet($this, 'deposits?status=0')['pagination']['total'])->toBe(1);
    expect(drillGet($this, 'deposits?kind=unmatched')['items'][0]['trans_id'])->toBe('DF3');
    expect(drillGet($this, 'deposits?customer_id=900')['items'])->toHaveCount(1);
});

it('filters refunded deposits and refuses a status label', function () {
    drillDeposit('DR1', 40, Deposit::STATUS_UNMATCHED);
    drillDeposit('DR2', 70, Deposit::STATUS_REFUNDED);

    $data = drillGet($this, 'deposits?status=4');

    expect($data['items'])->toHaveCount(1)
        ->and($data['items'][0])->toMatchArray(['trans_id' => 'DR2', 'status' => 'refunded'])
        ->and($data['summary']['by_status'])->toHaveKey('refunded');

    $this->getJson('/api/v1/finance/deposits?status=refunded', $this->headers)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');
});

it('still accepts text statuses on the other lists', function () {
    $this->getJson('/api/v1/finance/withdrawals?status=paid', $this->headers)->assertOk();
});

it('pages the results', function () {
    foreach (range(1, 5) as $n) {
        $deposit = drillDeposit("DP{$n}", 10);
        $this->ledger->recordDeposit($deposit, $this->alice->fresh(), 10.0);
    }

    $second = drillGet($this, 'deposits?per_page=2&page=2');

    expect($second['pagination'])->toBe(['page' => 2, 'per_page' => 2, 'total' => 5, 'last_page' => 3]);
    expect($second['items'])->toHaveCount(2);
    expect($second['items'][0]['trans_id'])->toBe('DP3');
});

// withdrawals

it('reports withdrawals by status with failures and how long pending ones have waited', function () {
    $wallet = $this->alice->fresh();
    $paidTx = $wallet->transactions()->create(['payment_type' => Withdraw::class, 'amount' => 150, 'status' => 2]);
    Withdraw::create(['transaction_id' => $paidTx->id, 'amount' => 150, 'disburse' => 2, 'receipt' => 'R1']);
    Withdraw::create(['amount' => 60, 'disburse' => 3, 'error_message' => 'Insufficient float']);
    Withdraw::create(['amount' => 40, 'disburse' => 3, 'error_message' => 'Insufficient float']);
    $old = Withdraw::create(['amount' => 80, 'disburse' => 1]);
    Withdraw::create(['amount' => 20, 'disburse' => 1]);
    DB::table('outgoing_payments')->where('id', $old->id)->update(['created_at' => '2026-09-19 06:00:00']);

    $data = drillGet($this, 'withdrawals?from=2026-09-19&to=2026-09-20');

    expect($data['summary']['by_status']['paid'])->toEqual(['payments' => 1, 'amount' => 150]);
    expect($data['summary']['by_status']['failed'])->toEqual(['payments' => 2, 'amount' => 100]);
    expect($data['summary']['by_status']['pending'])->toEqual(['payments' => 2, 'amount' => 100]);
    expect($data['summary']['failure_reasons'])->toEqual([['reason' => 'Insufficient float', 'payments' => 2, 'amount' => 100]]);
    expect($data['summary']['oldest_pending_hours'])->toBe(30);
    expect($data['summary']['stuck_pending'])->toEqual(['threshold_hours' => 24, 'payments' => 1, 'amount' => 80]);

    $paid = collect($data['items'])->firstWhere('receipt', 'R1');
    expect($paid)->toMatchArray(['status' => 'paid', 'customer_id' => 900, 'customer_name' => 'Alice Wanjiru', 'age_hours' => null]);
    expect(collect($data['items'])->firstWhere('id', $old->id)['age_hours'])->toBe(30);

    expect(drillGet($this, 'withdrawals?from=2026-09-19&to=2026-09-20&status=failed')['pagination']['total'])->toBe(2);
});

// purchases

it('reports purchases by type, ignores test purchases and returns a series', function () {
    Purchase::create(['customer_id' => 900, 'deposit_id' => drillDeposit('PU1', 300)->id, 'purchase_type' => 'load', 'amount' => 300, 'value' => 280]);
    Purchase::create(['customer_id' => 900, 'deposit_id' => drillDeposit('PU2', 100)->id, 'purchase_type' => 'gift', 'amount' => 100, 'value' => 0]);
    Purchase::create(['customer_id' => 901, 'deposit_id' => drillDeposit('PU3', 50)->id, 'purchase_type' => 'emoji', 'amount' => 50, 'value' => 0]);
    Purchase::create(['customer_id' => 2, 'deposit_id' => drillDeposit('PU4', 999)->id, 'purchase_type' => 'gift', 'amount' => 999, 'value' => 0]);

    $data = drillGet($this, 'purchases');

    expect($data['summary']['purchases'])->toBe(3);
    expect($data['summary']['amount'])->toEqual(450);
    expect($data['summary']['by_type']['load'])->toEqual(['purchases' => 1, 'amount' => 300, 'value' => 280]);
    expect($data['summary']['series'][0])->toEqual(['period' => '2026-09-20', 'load' => 300, 'gift' => 100, 'emoji' => 50, 'other' => 0, 'total' => 450]);
    expect(drillGet($this, 'purchases?type=gift')['pagination']['total'])->toBe(1);
    expect(drillGet($this, 'purchases?exclude_test=0')['pagination']['total'])->toBe(4);
});

// games

it('reports each game with stakes, payouts, outcome and house take', function () {
    drillGame($this, [$this->alice, $this->bob], 900, 'DG_DONE');
    drillGame($this, [$this->alice], null, 'DG_OPEN');
    drillGame($this, [$this->testWallet], 2, 'DG_TEST');

    $data = drillGet($this, 'games');
    $done = collect($data['items'])->firstWhere('game_id', 'DG_DONE');

    expect($data['pagination']['total'])->toBe(2);
    expect($done)->toMatchArray(['outcome' => 'completed', 'players' => 2, 'game_type' => 1]);
    expect($done['stakes'])->toEqual(200);
    expect($done['paid_to_players'])->toEqual(180);
    expect($done['house_payout'])->toEqual(10);
    expect($done['refunded'])->toEqual(0);
    expect($done['escrow_balance'])->toEqual(0);
    expect($done['house_take'])->toEqual(20);

    $open = collect($data['items'])->firstWhere('game_id', 'DG_OPEN');
    expect($open['outcome'])->toBe('open');
    expect($open['house_take'])->toBeNull();
    expect($open['escrow_balance'])->toEqual(95);
});

it('summarises games by outcome and player count and filters them', function () {
    drillGame($this, [$this->alice, $this->bob], 900, 'DG_A');
    drillGame($this, [$this->alice, $this->bob], 901, 'DG_B');
    $refunded = drillGame($this, [$this->alice], null, 'DG_R');
    $this->postJson('/api/v1/game/refund', ['game_wallet_id' => $refunded->id], $this->headers)->assertOk();

    $data = drillGet($this, 'games');

    expect($data['summary']['games'])->toBe(3);
    expect($data['summary']['by_outcome']['completed'])->toMatchArray(['games' => 2, 'stakes' => 400, 'paid_to_players' => 360, 'house_take' => 40]);
    expect($data['summary']['by_outcome']['refunded'])->toMatchArray(['games' => 1, 'stakes' => 100, 'refunded' => 100]);
    expect($data['summary']['by_players']['2']['games'])->toBe(2);
    expect($data['summary']['by_players']['1']['games'])->toBe(1);

    expect(drillGet($this, 'games?outcome=completed')['pagination']['total'])->toBe(2);
    expect(drillGet($this, 'games?players=1')['pagination']['total'])->toBe(1);
    expect(drillGet($this, 'games?game_type=2')['pagination']['total'])->toBe(0);
});

// competitions

it('reports each competition with entries, house cut, pool and what is still held', function () {
    drillCompetition($this, $this->alice, 'U100', 1, 3, 50, 5);
    drillCompetition($this, $this->bob, 'U100', 1, 3, 50, 5);
    drillCompetition($this, $this->bob, 'U200', 2, 13, 100, 20);
    drillCompetition($this, $this->testWallet, 'U300', 1, 3, 50, 5);

    $data = drillGet($this, 'competitions');
    $tournament = collect($data['items'])->firstWhere('cmp_uid', 'U100');

    expect($data['pagination']['total'])->toBe(2);
    expect($tournament)->toMatchArray(['type' => 'tournament', 'rounds' => 3, 'players' => 2]);
    expect($tournament['entries'])->toEqual(100);
    expect($tournament['house_cut'])->toEqual(10);
    expect($tournament['pool'])->toEqual(90);
    expect($tournament['prizes_paid'])->toEqual(0);
    expect($tournament['outstanding'])->toEqual(90);
    expect($tournament['unaccounted'])->toEqual(0);

    expect($data['summary']['competitions'])->toBe(2);
    expect($data['summary']['entries'])->toEqual(200);
    expect($data['summary']['house_cut'])->toEqual(30);
    expect($data['summary']['by_type_and_rounds'])->toHaveCount(2);

    expect(drillGet($this, 'competitions?game_type=2')['items'][0]['cmp_uid'])->toBe('U200');
    expect(drillGet($this, 'competitions?jp_rounds=3')['pagination']['total'])->toBe(1);
    expect(drillGet($this, 'competitions?exclude_test=0')['pagination']['total'])->toBe(3);
});

// ledger

it('lists ledger entries with filters and keeps house entries when test customers are excluded', function () {
    drillGame($this, [$this->alice], 900, 'DL_1');
    $deposit = drillDeposit('DL_D', 40);
    $this->ledger->recordDeposit($deposit, $this->alice->fresh(), 40.0);

    $all = drillGet($this, 'ledger');
    expect($all['pagination']['total'])->toBeGreaterThan(5);
    expect($all['summary']['entries'])->toBe($all['pagination']['total']);

    $cuts = drillGet($this, 'ledger?entry_type=house_cut');
    expect($cuts['pagination']['total'])->toBe(2);
    expect($cuts['items'][0])->toMatchArray(['category' => 'house_revenue', 'account' => 'house_wallet', 'wallet_type' => 'wallet']);
    expect($cuts['items'][0]['reference'])->toStartWith('GameTransaction#');
    expect($cuts['items'][0]['metadata'])->toHaveKey('source');

    $cashIn = drillGet($this, 'ledger?category=cash_in');
    expect($cashIn['pagination']['total'])->toBe(1);
    expect($cashIn['items'][0]['entry_type'])->toBe('deposit');

    expect(drillGet($this, 'ledger?wallet_type=game_wallet')['items'])->not->toBeEmpty();
    expect(drillGet($this, 'ledger?customer_id=900&entry_type=deposit')['pagination']['total'])->toBe(1);
    expect(drillGet($this, 'ledger?status=reversed')['pagination']['total'])->toBe(0);
    expect($all['summary']['by_entry_type'][0])->toHaveKeys(['entry_type', 'category', 'entries', 'debit', 'credit']);
});

// adjustments

it('lists manual adjustments with reason and actor', function () {
    $id = encryptId($this->alice->id);
    $this->putJson("/api/v1/wallets/{$id}/deposit", ['amount' => 50, 'reason' => 'promo bonus'], $this->headers)->assertStatus(201);
    $this->putJson("/api/v1/wallets/{$id}/withdraw", ['amount' => 20, 'reason' => 'chargeback'], $this->headers)->assertStatus(201);
    $this->putJson("/api/v1/wallets/{$id}/deposit", ['amount' => 5], $this->headers)->assertStatus(201);

    $data = drillGet($this, 'adjustments');

    expect($data['pagination']['total'])->toBe(3);
    expect($data['summary'])->toMatchArray(['entries' => 3, 'without_reason' => 1]);
    expect($data['summary']['credited'])->toEqual(55);
    expect($data['summary']['debited'])->toEqual(20);
    expect($data['summary']['net'])->toEqual(35);
    expect($data['summary']['by_actor'][0])->toMatchArray(['actor' => 'api_key:'.$this->apiKey->id, 'entries' => 3]);
    expect(array_column($data['summary']['by_reason'], 'reason'))->toEqualCanonicalizing(['promo bonus', 'chargeback', 'unspecified']);

    $promo = collect($data['items'])->firstWhere('reason', 'promo bonus');
    expect($promo)->toMatchArray(['direction' => 'credit', 'customer_name' => 'Alice Wanjiru', 'operation' => 'add_balance']);
    expect($promo['amount'])->toEqual(50);
});

// customer statement

it('gives a customer statement with opening and closing balances that reconcile', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-19 12:00:00', config('app.timezone')));
    $this->ledger->recordDeposit(drillDeposit('ST1', 200), $this->alice->fresh(), 200.0);

    $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00:00', config('app.timezone')));
    $this->ledger->recordDeposit(drillDeposit('ST2', 100), $this->alice->fresh(), 100.0);
    drillGame($this, [$this->alice], 900, 'ST_G');

    $data = drillGet($this, 'customers/'.encryptId(900).'/statement?from=2026-09-20&to=2026-09-20');

    expect($data['customer'])->toMatchArray(['id' => 900, 'name' => 'Alice Wanjiru', 'phone_no' => '2547****5678']);
    expect($data['statement']['opening_balance'])->toEqual(1200);
    expect($data['statement']['credits'])->toEqual(190);
    expect($data['statement']['debits'])->toEqual(100);
    expect($data['statement']['closing_balance'])->toEqual(1290);
    expect($data['statement']['entries'])->toBe(3);
    expect($data['statement']['reconciles'])->toBeTrue();
    expect($data['wallet']['balance_now'])->toEqual(1290);
    expect(array_column($data['items'], 'entry_type'))->toBe(['deposit', 'game_bet', 'game_payout']);
    expect($data['items'][2]['balance_after'])->toEqual(1290);
    expect($data['pagination']['total'])->toBe(3);
});

it('shows an empty statement at the current balance when nothing moved', function () {
    $data = drillGet($this, 'customers/'.encryptId(901).'/statement');

    expect($data['statement'])->toMatchArray(['entries' => 0, 'reconciles' => true]);
    expect($data['statement']['opening_balance'])->toEqual(1000);
    expect($data['statement']['closing_balance'])->toEqual(1000);
    expect($data['items'])->toBe([]);
});

it('returns 404 for a statement of an unknown customer or one without a wallet', function () {
    $this->getJson('/api/v1/finance/customers/'.encryptId(424242).'/statement', $this->headers)->assertNotFound();

    Customer::factory()->create(['id' => 950]);
    $this->getJson('/api/v1/finance/customers/'.encryptId(950).'/statement', $this->headers)->assertNotFound();
});

// top customers

it('ranks customers by activity and reports how concentrated balances are', function () {
    $this->ledger->recordDeposit(drillDeposit('TC1', 500), $this->alice->fresh(), 500.0);
    $this->ledger->recordDeposit(drillDeposit('TC2', 100), $this->bob->fresh(), 100.0);
    drillGame($this, [$this->alice, $this->bob], 901, 'TC_G');

    $data = drillGet($this, 'customers/top');

    expect(array_column($data['items'], 'customer_id'))->toBe([900, 901]);
    expect($data['items'][0])->toMatchArray(['customer_name' => 'Alice Wanjiru']);
    expect($data['items'][0]['deposited'])->toEqual(500);
    expect($data['items'][0]['staked'])->toEqual(100);
    expect($data['items'][0]['won'])->toEqual(0);
    expect($data['items'][0]['net_gaming'])->toEqual(-100);
    expect($data['items'][1]['won'])->toEqual(180);
    expect($data['items'][1]['net_gaming'])->toEqual(80);

    $byNet = drillGet($this, 'customers/top?sort=net_gaming');
    expect(array_column($byNet['items'], 'customer_id'))->toBe([901, 900]);

    expect(drillGet($this, 'customers/top?limit=1')['items'])->toHaveCount(1);
    expect($data['summary']['customer_wallets_total'])->toEqual(2000 + 500 + 100 - 100 - 100 + 180);
    expect($data['summary']['concentration']['share_percent'])->toEqual(100.0);
});

// validation and auth

it('rejects bad list parameters', function (string $query) {
    $this->getJson("/api/v1/finance/deposits?{$query}", $this->headers)->assertStatus(422);
})->with([
    'page size over the maximum' => ['per_page=201'],
    'page zero' => ['page=0'],
    'bad wallet type' => ['wallet_type=bank'],
    'range over 366 days' => ['from=2025-01-01&to=2026-09-20'],
    'unknown sort' => ['sort=luck'],
]);

it('requires an API key on every drill-down', function (string $path) {
    $this->getJson("/api/v1/finance/{$path}")->assertUnauthorized();
})->with(['deposits', 'withdrawals', 'purchases', 'games', 'competitions', 'ledger', 'adjustments', 'customers/top', 'export/ledger']);
