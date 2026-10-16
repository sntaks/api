<?php

use App\Http\Requests\FinanceReportRequest;
use App\Models\Coin;
use App\Models\CompetitionWallet;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\FinancialSnapshot;
use App\Models\GameWallet;
use App\Models\LedgerEntry;
use App\Models\MpesaBalance;
use App\Models\PendingBalance;
use App\Models\Wallet;
use App\Services\ChartOfAccounts;
use App\Services\FinanceDateRange;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function reportRequest(array $query): FinanceReportRequest
{
    $request = FinanceReportRequest::create('/finance/test', 'GET', $query);
    $request->setContainer(app())->setRedirector(app('redirect'));

    return $request;
}

function snapshotWallet(int $customerId, float $balance, ?int $walletId = null): Wallet
{
    Customer::factory()->create(['id' => $customerId]);

    return Wallet::forceCreate(['id' => $walletId ?? $customerId, 'customer_id' => $customerId, 'balance' => $balance]);
}

// fee schedule

it('checks legacy game credit cuts against the configured fee', function () {
    config(['finance.fees.game_credit' => 0.10]);

    $apiKey = createApiKey('test-finance-foundation-key');
    snapshotWallet(1, 0);
    $player = snapshotWallet(900, 200);
    $gameWallet = GameWallet::create(['game_id' => 'FOUND_001', 'game_type' => 1, 'balance' => 0]);
    legacyGameCreditStake($player, $gameWallet, 100);

    $houseCutRates = function () use ($apiKey) {
        Cache::flush();

        return collect($this->getJson('/api/v1/finance/reconciliation', apiHeaders($apiKey->key))->assertOk()->json('data.checks'))
            ->keyBy('key')['house_cut_rates'];
    };

    expect((float) Wallet::find(1)->balance)->toBe(10.0);
    expect($houseCutRates())->toMatchArray(['status' => 'pass', 'count' => 0]);

    config(['finance.fees.game_credit' => 0.05]);

    expect($houseCutRates())->toMatchArray(['status' => 'warn', 'count' => 1, 'amount' => 5]);
});

it('ships the current fee schedule and the game fees stack as confirmed', function () {
    expect(config('finance.fees'))->toBe([
        'game_credit' => 0.05,
        'game_withdrawal' => 0.05,
        'game_drop' => 0.10,
        'tournament' => 0.10,
        'jackpot' => 0.20,
    ]);
    expect(config('finance.coin_rate'))->toBe(0.04);
});

// date range

it('defaults to today in the app timezone', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-20 22:30:00', 'UTC')); // 01:30 on the 21st in Nairobi

    $range = FinanceDateRange::fromArray([]);

    expect($range->from->toDateString())->toBe('2026-09-21');
    expect($range->to->toDateString())->toBe('2026-09-21');
    expect($range->groupBy)->toBe('day');
    expect($range->excludeTest)->toBeTrue();
    expect($range->days())->toBe(1);
});

it('puts a late-evening UTC moment into the next Nairobi day', function () {
    $range = FinanceDateRange::fromArray(['from' => '2026-09-01', 'to' => '2026-09-30']);

    expect($range->bucketFor(CarbonImmutable::parse('2026-09-20 21:30:00', 'UTC')))->toBe('2026-09-21');
});

it('builds day, week and month buckets across the range', function () {
    $days = FinanceDateRange::fromArray(['from' => '2026-09-28', 'to' => '2026-10-02']);
    expect($days->buckets())->toBe(['2026-09-28', '2026-09-29', '2026-09-30', '2026-10-01', '2026-10-02']);

    $weeks = FinanceDateRange::fromArray(['from' => '2026-09-16', 'to' => '2026-09-30', 'group_by' => 'week']);
    expect($weeks->buckets())->toBe(['2026-09-14', '2026-09-21', '2026-09-28']);

    $months = FinanceDateRange::fromArray(['from' => '2026-08-15', 'to' => '2026-10-02', 'group_by' => 'month']);
    expect($months->buckets())->toBe(['2026-08', '2026-09', '2026-10']);
});

it('caches only windows that include today', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00:00', config('app.timezone')));

    expect(FinanceDateRange::fromArray(['from' => '2026-09-01', 'to' => '2026-09-20'])->cacheSeconds())->toBe(120);
    expect(FinanceDateRange::fromArray(['from' => '2026-09-01', 'to' => '2026-09-19'])->cacheSeconds())->toBeNull();
});

// report request

it('accepts an empty finance report request', function () {
    $request = reportRequest([]);
    $request->validateResolved();

    expect($request->dateRange()->days())->toBe(1);
});

it('accepts the maximum range and rejects one day more', function () {
    reportRequest(['from' => '2026-01-01', 'to' => '2026-12-31'])->validateResolved();

    expect(fn () => reportRequest(['from' => '2025-12-30', 'to' => '2026-12-31'])->validateResolved())
        ->toThrow(ValidationException::class);
});

it('rejects a reversed range, a bad date and an unknown grouping', function (array $query, string $field) {
    try {
        reportRequest($query)->validateResolved();
        $this->fail('Expected a validation error.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($field);
    }
})->with([
    'to before from' => [['from' => '2026-09-10', 'to' => '2026-09-01'], 'to'],
    'bad date' => [['from' => '20/09/2026'], 'from'],
    'unknown group_by' => [['group_by' => 'year'], 'group_by'],
    'bad exclude_test' => [['exclude_test' => 'maybe'], 'exclude_test'],
]);

it('reads exclude_test from the query and defaults it to true', function () {
    expect(reportRequest(['exclude_test' => '0'])->dateRange()->excludeTest)->toBeFalse();
    expect(reportRequest([])->dateRange()->excludeTest)->toBeTrue();
});

// chart of accounts

it('categorises ledger entry types and their reversals', function () {
    $chart = new ChartOfAccounts;

    expect($chart->categoryFor('deposit'))->toBe('cash_in');
    expect($chart->categoryFor('house_cut'))->toBe('house_revenue');
    expect($chart->categoryFor('withdrawal_reversal'))->toBe('cash_out');
    expect($chart->categoryFor('mystery'))->toBe('uncategorised');
    expect($chart->isReversal('withdrawal_reversal'))->toBeTrue();
    expect($chart->isReversal('withdrawal'))->toBeFalse();
});

it('categorises every entry type the ledger writes', function () {
    $written = ['deposit', 'withdrawal', 'game_bet', 'game_payout', 'competition_bet', 'competition_payout',
        'wallet_transfer', 'coin_purchase', 'coin_exchange', 'coin_transfer', 'house_cut', 'refund',
        'adjustment', 'escrow_release', 'escrow_transfer'];

    foreach ($written as $entryType) {
        expect((new ChartOfAccounts)->categoryFor($entryType))->not->toBe('uncategorised', $entryType);
    }
});

it('assigns wallets to accounts and keeps the house wallet apart', function () {
    $chart = new ChartOfAccounts;

    expect($chart->accountFor(LedgerEntry::WALLET_TYPE_WALLET, 55))->toBe('customer_wallets');
    expect($chart->accountFor(LedgerEntry::WALLET_TYPE_WALLET, 1))->toBe('house_wallet');
    expect($chart->accountFor(LedgerEntry::WALLET_TYPE_GAME, 1))->toBe('game_escrow');
    expect($chart->accountFor(LedgerEntry::WALLET_TYPE_COMPETITION, 1))->toBe('competition_escrow');
    expect($chart->accountFor(LedgerEntry::WALLET_TYPE_COIN, 1))->toBe('coin_wallets');
    expect($chart->account('customer_wallets'))->toBe(['label' => 'Customer wallets', 'type' => 'liability']);
});

// snapshot

it('snapshots balances excluding the house wallet and test customers', function () {
    config(['finance.test_customer_ids' => [1, 2]]);

    snapshotWallet(1, 300);      // house
    snapshotWallet(2, 5000);     // test customer
    snapshotWallet(900, 120.50);
    snapshotWallet(901, 79.50);

    GameWallet::create(['game_id' => 'SNAP_1', 'game_type' => 1, 'balance' => 40, 'status' => 1]);
    GameWallet::create(['game_id' => 'SNAP_2', 'game_type' => 1, 'balance' => 15, 'status' => 3]); // stuck
    CompetitionWallet::create(['competition_id' => 'C1', 'cmp_uid' => '1', 'game_type' => 1, 'customer_id' => 900, 'balance' => 60, 'status' => 1]);
    CompetitionWallet::create(['competition_id' => 'C2', 'cmp_uid' => '2', 'game_type' => 1, 'customer_id' => 901, 'balance' => 10, 'status' => 3]); // stuck

    Coin::create(['customer_id' => 2, 'coins' => 1000, 'status' => 1]); // test customer
    Coin::create(['customer_id' => 900, 'coins' => 500, 'status' => 1]);

    PendingBalance::create(['pending_id' => 'p1', 'wallet_id' => 900, 'amount' => 25, 'type' => 'x', 'status' => 'holding']);
    PendingBalance::create(['pending_id' => 'p2', 'wallet_id' => 900, 'amount' => 99, 'type' => 'x', 'status' => 'settled']);

    Deposit::create(['trans_id' => 'D1', 'trans_time' => '20260920100000', 'trans_amount' => 200, 'status' => 0]);
    Deposit::create(['trans_id' => 'D2', 'trans_time' => '20260920100001', 'trans_amount' => 300, 'status' => 2]);

    $this->artisan('finance:snapshot')->assertSuccessful();

    $snapshot = FinancialSnapshot::firstOrFail();

    expect($snapshot->snapshot_date->toDateString())->toBe(now()->toDateString());
    expect((float) $snapshot->customer_wallets_total)->toBe(200.0);
    expect((float) $snapshot->house_wallet_balance)->toBe(300.0);
    expect((float) $snapshot->game_escrow_total)->toBe(40.0);
    expect((float) $snapshot->competition_escrow_total)->toBe(60.0);
    expect((float) $snapshot->stuck_escrow_total)->toBe(25.0);
    expect((float) $snapshot->coin_liability)->toBe(20.0);
    expect((float) $snapshot->pending_holds_total)->toBe(25.0);
    expect((float) $snapshot->unmatched_deposits_total)->toBe(200.0);
    expect($snapshot->mpesa_balances)->toBeNull();
});

it('keeps one snapshot per day and overwrites it on a rerun', function () {
    snapshotWallet(1, 0);
    $wallet = snapshotWallet(900, 100);

    $this->artisan('finance:snapshot')->assertSuccessful();
    $wallet->update(['balance' => 250]);
    $this->artisan('finance:snapshot')->assertSuccessful();

    expect(FinancialSnapshot::count())->toBe(1);
    expect((float) FinancialSnapshot::first()->customer_wallets_total)->toBe(250.0);

    $this->travel(1)->days();
    $this->artisan('finance:snapshot')->assertSuccessful();

    expect(FinancialSnapshot::count())->toBe(2);
});

it('stores the latest M-Pesa balance for each account', function () {
    snapshotWallet(1, 0);

    MpesaBalance::create(['type' => 'b2c', 'account_name' => 'Utility Account', 'currency' => 'KES', 'amount' => 1000]);
    MpesaBalance::create(['type' => 'b2c', 'account_name' => 'Utility Account', 'currency' => 'KES', 'amount' => 800]);
    MpesaBalance::create(['type' => 'c2b', 'account_name' => 'Working Account', 'currency' => 'KES', 'amount' => 5500]);

    $this->artisan('finance:snapshot')->assertSuccessful();

    $balances = FinancialSnapshot::firstOrFail()->mpesa_balances;

    expect($balances['b2c']['Utility Account']['amount'])->toEqual(800.0);
    expect($balances['c2b']['Working Account']['amount'])->toEqual(5500.0);
});

it('schedules the snapshot daily at the configured time', function () {
    Artisan::call('schedule:list');

    $output = Artisan::output();

    expect($output)->toMatch('/59\s+23 \* \* \*\s+php artisan finance:snapshot/');
});

it('expires uncollected shop withdrawals every five minutes', function () {
    Artisan::call('schedule:list');

    expect(Artisan::output())->toMatch('/\*\/5\s+\*\s+\* \* \*\s+php artisan shop-withdrawals:expire/');
});

it('has a factory for snapshots', function () {
    expect(FinancialSnapshot::factory()->create()->exists)->toBeTrue();
});

// indexes

it('adds the report indexes', function () {
    expect(Schema::hasIndex('ledger_entries', 'idx_entry_type_created_at'))->toBeTrue();
    expect(Schema::hasIndex('ledger_entries', 'idx_customer_id_created_at'))->toBeTrue();
    expect(Schema::hasIndex('game_transactions', 'idx_payment_type_created_at'))->toBeTrue();
    expect(Schema::hasIndex('competition_transactions', 'idx_payment_type_created_at'))->toBeTrue();
    expect(Schema::hasIndex('incoming_payments', 'idx_status_created_at'))->toBeTrue();
    expect(Schema::hasIndex('outgoing_payments', 'idx_disburse_created_at'))->toBeTrue();
    expect(Schema::hasIndex('purchases', 'idx_purchase_type_created_at'))->toBeTrue();
    expect(Schema::hasIndex('wallet_transactions', 'idx_transaction_type_created_at'))->toBeTrue();
});
