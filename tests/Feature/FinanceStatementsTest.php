<?php

use App\Models\CompetitionTransaction;
use App\Models\CompetitionWallet;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\GameWallet;
use App\Models\LedgerEntry;
use App\Models\MpesaBalance;
use App\Models\Purchase;
use App\Models\Wallet;
use App\Models\Withdraw;
use App\Services\LedgerService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00:00', config('app.timezone')));
    config(['finance.test_customer_ids' => [1, 2]]);

    $this->apiKey = createApiKey('test-finance-statements-key');
    $this->headers = apiHeaders($this->apiKey->key);
    $this->ledger = app(LedgerService::class);

    $this->house = statementWallet(1, 0);
    $this->testWallet = statementWallet(2, 1000);
    $this->alice = statementWallet(900, 1000);
    $this->bob = statementWallet(901, 1000);
});

function statementWallet(int $customerId, float $balance): Wallet
{
    Customer::factory()->create(['id' => $customerId]);

    return Wallet::forceCreate(['id' => $customerId, 'customer_id' => $customerId, 'balance' => $balance]);
}

function statementDeposit(string $transId, float $amount, int $status = 2): Deposit
{
    return Deposit::create(['trans_id' => $transId, 'trans_time' => '20260920100000', 'trans_amount' => $amount, 'status' => $status]);
}

function playGame(object $test, Wallet $player, string $gameId): void
{
    $gameWallet = GameWallet::create(['game_id' => $gameId, 'game_type' => 1, 'balance' => 0]);

    legacyGameCreditStake($player, $gameWallet, 100);

    $test->postJson('/api/v1/game/withdraw/'.encryptId($gameWallet->id), [
        'customer_id' => (string) $player->customer_id,
    ], $test->headers)->assertStatus(201);
}

function competitionEntry(object $test, Wallet $player, int $gameType, int $rounds, float $amount, float $cut): void
{
    $competitionWallet = CompetitionWallet::create([
        'competition_id' => "C-{$gameType}-{$player->id}-{$rounds}", 'cmp_uid' => (string) fake()->unique()->numberBetween(1, 999999),
        'game_type' => $gameType, 'customer_id' => $player->customer_id, 'jp_rounds' => $rounds, 'balance' => 0, 'status' => 1,
    ]);
    $transaction = CompetitionTransaction::create([
        'competition_wallet_id' => $competitionWallet->id, 'customer_id' => $player->customer_id,
        'payment_type' => 'deposit', 'amount' => $amount, 'status' => 2,
    ]);

    $test->ledger->recordCompetitionBet($transaction, $player->fresh(), $competitionWallet, $amount, $cut);
    $test->ledger->recordHouseCut($test->house->fresh(), $cut, 'competition_bet', $transaction, ['competition_wallet_id' => $competitionWallet->id]);
}

/**
 * Real activity: games, competitions, deposits, gifts, loads and withdrawals for two real customers
 * and one test customer.
 */
function seedActivity(object $test): void
{
    // wallet deposit (500) and load (cash 300, wallet credit 280) for real customers
    $deposit = statementDeposit('D1', 500);
    $test->ledger->recordDeposit($deposit, $test->alice->fresh(), 500.0);

    $load = statementDeposit('D5', 300);
    $test->ledger->recordDeposit($load, $test->bob->fresh(), 280.0);
    Purchase::create(['customer_id' => 901, 'deposit_id' => $load->id, 'purchase_type' => 'load', 'amount' => 300, 'value' => 280]);

    // gift by a real customer, emoji by the test customer, and an unmatched deposit
    $gift = statementDeposit('D2', 100);
    Purchase::create(['customer_id' => 900, 'deposit_id' => $gift->id, 'purchase_type' => 'gift', 'amount' => 100, 'value' => 0]);
    $emoji = statementDeposit('D3', 50);
    Purchase::create(['customer_id' => 2, 'deposit_id' => $emoji->id, 'purchase_type' => 'emoji', 'amount' => 50, 'value' => 0]);
    statementDeposit('D4', 200, 0);

    // withdrawals: paid 150, pending 40, failed 60
    Withdraw::create(['amount' => 150, 'disburse' => 2]);
    Withdraw::create(['amount' => 40, 'disburse' => 1]);
    Withdraw::create(['amount' => 60, 'disburse' => 3]);

    // games: real player 5 + 5 cut, test player 5 + 5 cut
    playGame($test, $test->alice, 'STMT_A');
    playGame($test, $test->testWallet, 'STMT_T');

    // competitions: real tournament cut 5, real jackpot cut 20, test tournament cut 5
    competitionEntry($test, $test->alice, 1, 3, 50, 5);
    competitionEntry($test, $test->bob, 2, 13, 100, 20);
    competitionEntry($test, $test->testWallet, 1, 3, 50, 5);
}

// income statement

it('reports revenue by stream and leaves out test customers', function () {
    seedActivity($this);

    $data = $this->getJson('/api/v1/finance/income-statement', $this->headers)->assertOk()->json('data');

    expect($data['revenue']['games'])->toEqual(9);
    expect($data['revenue']['games_by_source'])->toEqual(['game_credit' => 5, 'game_withdrawal' => 4]);
    expect($data['revenue']['tournaments']['total'])->toEqual(5);
    expect($data['revenue']['tournaments']['by_rounds'])->toEqual(['3' => 5]);
    expect($data['revenue']['jackpots']['total'])->toEqual(20);
    expect($data['revenue']['jackpots']['by_rounds'])->toEqual(['13' => 20]);
    expect($data['revenue']['gift_emoji_sales'])->toEqual(['total' => 100, 'gift' => 100, 'emoji' => 0]);
    expect($data['revenue']['total'])->toEqual(134);
    expect($data['expenses'])->toEqual(['tracked' => true, 'total' => 0, 'by_category' => []]);
    expect($data['net_income'])->toEqual(134);
    expect($data['memo']['load_margin'])->toEqual(['cash_received' => 300, 'wallet_credited' => 280, 'margin' => 20]);
    expect($data['meta']['period'])->toMatchArray(['from' => '2026-09-20', 'to' => '2026-09-20', 'group_by' => 'day', 'exclude_test' => true]);
});

it('includes test customers when exclude_test is off', function () {
    seedActivity($this);

    $data = $this->getJson('/api/v1/finance/income-statement?exclude_test=0', $this->headers)->assertOk()->json('data');

    expect($data['revenue']['games'])->toEqual(18);
    expect($data['revenue']['tournaments']['total'])->toEqual(10);
    expect($data['revenue']['gift_emoji_sales']['total'])->toEqual(150);
    expect($data['revenue']['total'])->toEqual(198);
});

it('returns a zero-filled series grouped by day, week or month', function () {
    seedActivity($this);

    $days = $this->getJson('/api/v1/finance/income-statement?from=2026-09-18&to=2026-09-20', $this->headers)->json('data.series');
    expect(array_column($days, 'period'))->toBe(['2026-09-18', '2026-09-19', '2026-09-20']);
    expect($days[0]['total'])->toEqual(0);
    expect($days[2]['total'])->toEqual(134);

    $weeks = $this->getJson('/api/v1/finance/income-statement?from=2026-09-01&to=2026-09-20&group_by=week', $this->headers)->json('data.series');
    expect(array_column($weeks, 'period'))->toBe(['2026-08-31', '2026-09-07', '2026-09-14']);
    expect($weeks[2]['total'])->toEqual(134);

    $months = $this->getJson('/api/v1/finance/income-statement?from=2026-09-01&to=2026-09-20&group_by=month', $this->headers)->json('data.series');
    expect(array_column($months, 'period'))->toBe(['2026-09']);
});

it('lists competition cuts booked without a link as unattributed', function () {
    $this->ledger->recordHouseCut($this->house->fresh(), 12, 'competition_bet');

    $data = $this->getJson('/api/v1/finance/income-statement', $this->headers)->json('data');

    expect($data['revenue']['competitions_unattributed'])->toEqual(12);
    expect($data['revenue']['tournaments']['total'])->toEqual(0);
});

it('warns when the range starts before the ledger began', function () {
    $warnings = $this->getJson('/api/v1/finance/income-statement?from=2026-08-25&to=2026-09-20', $this->headers)->json('data.meta.warnings');

    expect($warnings)->not->toBeEmpty();
    expect($warnings[0])->toContain('2026-09-01');
});

// cash flow

it('splits cash in by what it bought and cash out by status', function () {
    seedActivity($this);

    $totals = $this->getJson('/api/v1/finance/cash-flow', $this->headers)->assertOk()->json('data.totals');

    expect($totals['cash_in'])->toEqual([
        'wallet_deposit' => 500, 'load' => 300, 'gift' => 100, 'emoji' => 0, 'unmatched' => 200, 'house_funding' => 0, 'other' => 0, 'total' => 1100,
    ]);
    expect($totals['cash_out'])->toEqual(['paid' => 150, 'pending' => 40, 'failed' => 60]);
    expect($totals['net_cash'])->toEqual(950);
});

it('counts test customer cash when exclude_test is off', function () {
    seedActivity($this);

    $totals = $this->getJson('/api/v1/finance/cash-flow?exclude_test=false', $this->headers)->json('data.totals');

    expect($totals['cash_in']['emoji'])->toEqual(50);
    expect($totals['cash_in']['total'])->toEqual(1150);
});

// summary

it('summarises every window and the current position', function () {
    seedActivity($this);
    MpesaBalance::create(['type' => 'b2c', 'account_name' => 'Utility Account', 'currency' => 'KES', 'amount' => 1000]);

    $data = $this->getJson('/api/v1/finance/summary', $this->headers)->assertOk()->json('data');

    foreach (['today', 'week', 'month', 'year', 'all_time'] as $window) {
        expect($data['windows'][$window]['revenue']['total'])->toEqual(134);
        expect($data['windows'][$window]['cash_in'])->toEqual(1100);
        expect($data['windows'][$window]['cash_out'])->toEqual(150);
    }

    expect($data['windows']['today']['stakes'])->toEqual(250);
    expect($data['windows']['today']['payouts'])->toEqual(91);
    expect($data['windows']['today']['refunds'])->toEqual(0);
    expect($data['all_time_from'])->toBe('2026-09-01');
    expect($data['position']['source'])->toBe('live');
    expect($data['position']['assets']['cash']['total'])->toEqual(1000);
});

// balance sheet

it('builds the balance sheet from live balances', function () {
    MpesaBalance::create(['type' => 'b2c', 'account_name' => 'Utility Account', 'currency' => 'KES', 'amount' => 1000]);
    MpesaBalance::create(['type' => 'c2b', 'account_name' => 'Working Account', 'currency' => 'KES', 'amount' => 2500]);
    $this->house->update(['balance' => 300]);
    GameWallet::create(['game_id' => 'BS_1', 'game_type' => 1, 'balance' => 40, 'status' => 1]);
    statementDeposit('BS_D', 75, 0);

    $data = $this->getJson('/api/v1/finance/balance-sheet', $this->headers)->assertOk()->json('data');

    expect($data['source'])->toBe('live');
    expect($data['assets']['cash']['total'])->toEqual(3500);
    expect(array_column($data['assets']['cash']['accounts'], 'account'))->toEqualCanonicalizing(['Utility Account', 'Working Account']);
    expect($data['liabilities']['customer_wallets'])->toEqual(2000);
    expect($data['liabilities']['game_escrow'])->toEqual(40);
    expect($data['liabilities']['unmatched_deposits'])->toEqual(75);
    expect($data['liabilities']['total'])->toEqual(2115);
    expect($data['house_wallet'])->toEqual(300);
    expect($data['difference'])->toEqual(1085);
});

it('reads a past balance sheet from the daily snapshot', function () {
    $this->artisan('finance:snapshot')->assertSuccessful();
    $this->alice->update(['balance' => 5000]);

    $data = $this->getJson('/api/v1/finance/balance-sheet?as_of=2026-09-20', $this->headers)->assertOk()->json('data');

    expect($data['source'])->toBe('snapshot');
    expect($data['liabilities']['customer_wallets'])->toEqual(2000);

    $this->getJson('/api/v1/finance/balance-sheet?as_of=2026-09-10', $this->headers)->assertNotFound();
    $this->getJson('/api/v1/finance/balance-sheet?as_of=2026-09-25', $this->headers)->assertStatus(422);
});

// trial balance

it('shows movement by account and confirms paired entries balance', function () {
    seedActivity($this);

    $data = $this->getJson('/api/v1/finance/trial-balance', $this->headers)->assertOk()->json('data');

    expect($data['check']['balanced'])->toBeTrue();
    expect($data['check']['imbalance'])->toEqual(0);

    $lines = collect($data['lines']);
    $houseCuts = $lines->firstWhere(fn ($line) => $line['account'] === 'house_wallet' && $line['entry_type'] === 'house_cut');
    expect($houseCuts['credit'])->toEqual(48);
    expect($houseCuts['category'])->toBe('house_revenue');
    expect($lines->firstWhere('entry_type', 'deposit')['category'])->toBe('cash_in');
});

it('reports an imbalance when money is credited without a matching debit', function () {
    $this->ledger->recordRefund(null, $this->alice->fresh(), 10.0, 'test refund');

    $check = $this->getJson('/api/v1/finance/trial-balance', $this->headers)->json('data.check');

    expect($check['balanced'])->toBeFalse();
    expect($check['imbalance'])->toEqual(10);
    expect($check['imbalance_by_category'])->toEqual(['refund' => 10]);
});

// validation, auth and caching

it('rejects bad report parameters', function (string $query) {
    $this->getJson("/api/v1/finance/cash-flow?{$query}", $this->headers)->assertStatus(422);
})->with([
    'range over 366 days' => ['from=2025-01-01&to=2026-09-20'],
    'reversed range' => ['from=2026-09-20&to=2026-09-01'],
    'unknown grouping' => ['group_by=year'],
    'bad date' => ['from=yesterday'],
]);

it('requires an API key', function () {
    $this->getJson('/api/v1/finance/summary')->assertUnauthorized();
});

it('caches a window that includes today and serves the cached copy', function () {
    statementDeposit('CACHE_1', 100);
    $first = $this->getJson('/api/v1/finance/cash-flow', $this->headers)->json('data.totals.cash_in.total');

    statementDeposit('CACHE_2', 100);
    $second = $this->getJson('/api/v1/finance/cash-flow', $this->headers)->json('data.totals.cash_in.total');

    expect($first)->toEqual(100);
    expect($second)->toEqual(100);

    Cache::flush();
    expect($this->getJson('/api/v1/finance/cash-flow', $this->headers)->json('data.totals.cash_in.total'))->toEqual(200);
});

it('makes ledger entries visible to the reports only inside the range', function () {
    $this->ledger->recordHouseCut($this->house->fresh(), 7, 'game_credit');
    LedgerEntry::where('entry_type', 'house_cut')->update(['created_at' => '2026-09-10 12:00:00']);

    $today = $this->getJson('/api/v1/finance/income-statement', $this->headers)->json('data.revenue.total');
    $earlier = $this->getJson('/api/v1/finance/income-statement?from=2026-09-10&to=2026-09-10', $this->headers)->json('data.revenue.total');

    expect($today)->toEqual(0);
    expect($earlier)->toEqual(7);
});
