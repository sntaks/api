<?php

use App\Models\CompetitionTransaction;
use App\Models\CompetitionWallet;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\GameTransaction;
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
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00:00', config('app.timezone')));

    $this->apiKey = createApiKey('test-finance-recon-key');
    $this->headers = apiHeaders($this->apiKey->key);
    $this->ledger = app(LedgerService::class);

    Customer::factory()->create(['id' => 1]);
    $this->house = Wallet::forceCreate(['id' => 1, 'customer_id' => 1, 'balance' => 0]);
    Customer::factory()->create(['id' => 900]);
    $this->alice = Wallet::forceCreate(['id' => 900, 'customer_id' => 900, 'balance' => 1000]);
});

function reconcile(object $test, string $query = ''): array
{
    Cache::flush();

    return $test->getJson('/api/v1/finance/reconciliation'.$query, $test->headers)->assertOk()->json('data');
}

function checkOf(array $report, string $key): array
{
    return collect($report['checks'])->firstWhere('key', $key);
}

function reconDeposit(string $transId, float $amount, int $status = 2): Deposit
{
    return Deposit::create(['trans_id' => $transId, 'trans_time' => '20260920100000', 'trans_amount' => $amount, 'status' => $status]);
}

function backdate(string $table, int $id, string $column, string $when): void
{
    DB::table($table)->where('id', $id)->update([$column => $when]);
}

it('reports every check and passes on a clean ledger', function () {
    $deposit = reconDeposit('R1', 200);
    $this->ledger->recordDeposit($deposit, $this->alice->fresh(), 200.0);
    MpesaBalance::create(['type' => 'c2b', 'account_name' => 'Working Account', 'currency' => 'KES', 'amount' => 5000]);

    $report = reconcile($this);

    expect(array_column($report['checks'], 'key'))->toBe([
        'customer_wallet_drift', 'game_wallet_drift', 'competition_wallet_drift', 'dispute_escrow_drift', 'referral_wallet_drift', 'bet_shop_float_drift', 'shop_withdrawal_drift', 'ledger_arithmetic', 'ledger_balance',
        'unclassified_entries', 'unmatched_deposits', 'deposit_resolutions', 'deposits_without_ledger', 'deposits_without_excise_duty',
        'excise_duty_amounts', 'excise_duty_ledger', 'overdue_excise_duty', 'stuck_withdrawals', 'withdrawals_awaiting_approval',
        'failed_withdrawals_not_reversed', 'stuck_escrow', 'aged_escrow', 'negative_balances',
        'held_on_closed_complaints', 'aged_disputes', 'stuck_referral_withdrawals',
        'failed_referral_withdrawals_not_reversed', 'referral_bonuses_unverified', 'promotion_credits_ledger', 'house_funding_ledger', 'shop_float_ledger', 'shop_loads_ledger', 'stale_shop_withdrawals', 'held_on_closed_shop_withdrawals', 'house_cut_rates', 'mpesa_balance_freshness', 'cash_coverage',
    ]);
    expect($report['status'])->toBe('pass');
    expect($report['counts'])->toBe(['pass' => 37, 'warn' => 0, 'fail' => 0]);
    expect($report['meta']['period']['from'])->toBe('2026-08-22');
});

it('flags a customer wallet that drifted from the ledger', function () {
    $this->ledger->recordDeposit(reconDeposit('R2', 200), $this->alice->fresh(), 200.0);
    $this->alice->update(['balance' => 1500]);

    $check = checkOf(reconcile($this), 'customer_wallet_drift');

    expect($check['status'])->toBe('fail');
    expect($check['count'])->toBe(1);
    expect($check['amount'])->toEqual(300);
    expect($check['samples'][0])->toMatchArray(['id' => 900, 'balance' => 1500.0, 'expected' => 1200.0, 'drift' => 300.0]);
});

it('ignores drift under the tolerance', function () {
    $this->ledger->recordDeposit(reconDeposit('R3', 200), $this->alice->fresh(), 200.0);
    $this->alice->update(['balance' => 1200.005]);

    expect(checkOf(reconcile($this), 'customer_wallet_drift')['status'])->toBe('pass');
});

it('flags a game wallet whose balance drifted from the ledger', function () {
    $player = $this->alice->fresh();
    $gameWallet = GameWallet::create(['game_id' => 'RECON_G', 'game_type' => 1, 'balance' => 0]);
    $transaction = GameTransaction::create(['game_wallet_id' => $gameWallet->id, 'customer_id' => 900, 'payment_type' => 'deposit', 'amount' => 100, 'status' => 2]);
    $this->ledger->recordGameBet($transaction, $player, $gameWallet, 100.0);

    expect(checkOf(reconcile($this), 'game_wallet_drift')['status'])->toBe('pass');

    $gameWallet->update(['balance' => 250]);

    $check = checkOf(reconcile($this), 'game_wallet_drift');
    expect($check['status'])->toBe('fail');
    expect($check['samples'][0]['drift'])->toEqual(150);
});

it('flags ledger entries that do not add up', function () {
    LedgerEntry::create([
        'entry_id' => 'broken-1', 'entry_type' => 'deposit', 'wallet_type' => 'wallet', 'wallet_id' => 900,
        'customer_id' => 900, 'debit' => 0, 'credit' => 100, 'balance_before' => 0, 'balance_after' => 250, 'status' => 'settled',
    ]);

    $check = checkOf(reconcile($this), 'ledger_arithmetic');

    expect($check['status'])->toBe('fail');
    expect($check['amount'])->toEqual(150);
    expect($check['samples'][0]['entry_id'])->toBe('broken-1');
});

it('flags paired ledger entries that do not net to zero', function () {
    $this->ledger->recordRefund(null, $this->alice->fresh(), 10.0, 'no escrow release');

    $check = checkOf(reconcile($this), 'ledger_balance');

    expect($check['status'])->toBe('fail');
    expect($check['amount'])->toEqual(10);
    expect($check['samples'])->toEqual([['category' => 'refund', 'net' => 10]]);
});

it('only checks the ledger balance from the day escrow releases were ledgered', function () {
    config(['finance.ledger_reliable_from' => '2026-09-25']);
    $this->ledger->recordRefund(null, $this->alice->fresh(), 10.0, 'before the reliable date');

    $check = checkOf(reconcile($this, '?from=2026-09-01&to=2026-09-20'), 'ledger_balance');

    expect($check['status'])->toBe('pass');
});

it('flags entries without a wallet type', function () {
    LedgerEntry::create([
        'entry_id' => 'legacy-1', 'entry_type' => 'deposit', 'wallet_id' => 900, 'customer_id' => 900,
        'debit' => 0, 'credit' => 5, 'balance_before' => 0, 'balance_after' => 5, 'status' => 'settled',
    ]);

    $check = checkOf(reconcile($this), 'unclassified_entries');

    expect($check['status'])->toBe('warn');
    expect($check['count'])->toBe(1);
});

it('flags deposits from unknown customers as money owed', function () {
    reconDeposit('R4', 200, 0);
    reconDeposit('R5', 75, 0);
    reconDeposit('R6', 999, 2);

    $check = checkOf(reconcile($this), 'unmatched_deposits');

    expect($check['status'])->toBe('warn');
    expect($check['count'])->toBe(2);
    expect($check['amount'])->toEqual(275);
});

it('flags processed deposits that never reached the ledger but not gifts', function () {
    reconDeposit('R7', 120);

    $gift = reconDeposit('R8', 60);
    Purchase::create(['customer_id' => 900, 'deposit_id' => $gift->id, 'purchase_type' => 'gift', 'amount' => 60, 'value' => 0]);

    $credited = reconDeposit('R9', 90);
    $this->ledger->recordDeposit($credited, $this->alice->fresh(), 90.0);

    $check = checkOf(reconcile($this), 'deposits_without_ledger');

    expect($check['status'])->toBe('fail');
    expect($check['count'])->toBe(1);
    expect($check['amount'])->toEqual(120);
});

it('flags withdrawals pending longer than the threshold', function () {
    $old = Withdraw::create(['amount' => 80, 'disburse' => 1]);
    $recent = Withdraw::create(['amount' => 20, 'disburse' => 1]);
    backdate('outgoing_payments', $old->id, 'created_at', '2026-09-19 10:00:00');
    backdate('outgoing_payments', $recent->id, 'created_at', '2026-09-20 11:00:00');

    $check = checkOf(reconcile($this), 'stuck_withdrawals');

    expect($check['status'])->toBe('warn');
    expect($check['count'])->toBe(1);
    expect($check['amount'])->toEqual(80);
});

it('flags a failed withdrawal that was never returned and clears once it is reversed', function () {
    $withdraw = Withdraw::create(['amount' => 50, 'disburse' => 3, 'error_message' => 'declined']);
    $this->ledger->recordWithdrawal($withdraw, $this->alice->fresh(), 50.0);

    $check = checkOf(reconcile($this), 'failed_withdrawals_not_reversed');
    expect($check['status'])->toBe('fail');
    expect($check['amount'])->toEqual(50);

    $this->ledger->reverseWithdrawal($withdraw);

    expect(checkOf(reconcile($this), 'failed_withdrawals_not_reversed')['status'])->toBe('pass');
});

it('flags money left in closed game and competition wallets', function () {
    GameWallet::create(['game_id' => 'RECON_C', 'game_type' => 1, 'balance' => 15, 'status' => 3]);
    CompetitionWallet::create(['competition_id' => 'RC', 'cmp_uid' => '1', 'game_type' => 1, 'customer_id' => 900, 'balance' => 10, 'status' => 3]);
    GameWallet::create(['game_id' => 'RECON_O', 'game_type' => 1, 'balance' => 99, 'status' => 1]);

    $check = checkOf(reconcile($this), 'stuck_escrow');

    expect($check['status'])->toBe('warn');
    expect($check['count'])->toBe(2);
    expect($check['amount'])->toEqual(25);
});

it('flags open wallets holding money untouched for too long', function () {
    $stale = GameWallet::create(['game_id' => 'RECON_S', 'game_type' => 1, 'balance' => 40, 'status' => 1]);
    $fresh = GameWallet::create(['game_id' => 'RECON_F', 'game_type' => 1, 'balance' => 60, 'status' => 1]);
    backdate('game_wallets', $stale->id, 'updated_at', '2026-09-19 08:00:00');

    $check = checkOf(reconcile($this), 'aged_escrow');

    expect($check['status'])->toBe('warn');
    expect($check['count'])->toBe(1);
    expect($check['amount'])->toEqual(40);
    expect($fresh->exists)->toBeTrue();
});

it('flags negative balances', function () {
    $this->alice->update(['balance' => -5]);

    $check = checkOf(reconcile($this), 'negative_balances');

    expect($check['status'])->toBe('fail');
    expect($check['amount'])->toEqual(5);
});

it('flags house cuts that do not match the fee schedule', function () {
    $gameWallet = GameWallet::create(['game_id' => 'RECON_R', 'game_type' => 1, 'balance' => 0]);
    $ok = GameTransaction::create(['game_wallet_id' => $gameWallet->id, 'customer_id' => 900, 'payment_type' => 'deposit', 'amount' => 100, 'status' => 2]);
    $wrong = GameTransaction::create(['game_wallet_id' => $gameWallet->id, 'customer_id' => 900, 'payment_type' => 'deposit', 'amount' => 100, 'status' => 2]);

    $this->ledger->recordHouseCut($this->house->fresh(), 5, 'game_credit', $ok);
    $this->ledger->recordHouseCut($this->house->fresh(), 9, 'game_credit', $wrong);

    $competitionWallet = CompetitionWallet::create(['competition_id' => 'RR', 'cmp_uid' => '2', 'game_type' => 2, 'customer_id' => 900, 'jp_rounds' => 13, 'balance' => 0, 'status' => 1]);
    $entry = CompetitionTransaction::create(['competition_wallet_id' => $competitionWallet->id, 'customer_id' => 900, 'payment_type' => 'deposit', 'amount' => 100, 'status' => 2]);
    $this->ledger->recordHouseCut($this->house->fresh(), 20, 'competition_bet', $entry);

    $check = checkOf(reconcile($this), 'house_cut_rates');

    expect($check['status'])->toBe('warn');
    expect($check['count'])->toBe(1);
    expect($check['amount'])->toEqual(4);
    expect($check['samples'][0])->toMatchArray(['source' => 'game_credit']);
});

it('flags M-Pesa balances that are missing or stale', function () {
    expect(checkOf(reconcile($this), 'mpesa_balance_freshness')['status'])->toBe('warn');

    $balance = MpesaBalance::create(['type' => 'b2c', 'account_name' => 'Utility Account', 'currency' => 'KES', 'amount' => 100]);
    expect(checkOf(reconcile($this), 'mpesa_balance_freshness')['status'])->toBe('pass');

    backdate('mpesa_balances', $balance->id, 'created_at', '2026-09-20 05:00:00');
    expect(checkOf(reconcile($this), 'mpesa_balance_freshness')['status'])->toBe('warn');
});

it('flags when M-Pesa cash does not cover what customers are owed', function () {
    $notFetched = checkOf(reconcile($this), 'cash_coverage');
    expect($notFetched['status'])->toBe('warn');

    MpesaBalance::create(['type' => 'c2b', 'account_name' => 'Working Account', 'currency' => 'KES', 'amount' => 400]);

    $check = checkOf(reconcile($this), 'cash_coverage');
    expect($check['status'])->toBe('fail');
    expect($check['amount'])->toEqual(600);
    expect($check['samples'][0])->toEqual(['cash' => 400, 'owed' => 1000, 'surplus' => -600]);

    MpesaBalance::create(['type' => 'c2b', 'account_name' => 'Working Account', 'currency' => 'KES', 'amount' => 1500]);
    expect(checkOf(reconcile($this), 'cash_coverage')['status'])->toBe('pass');
});

it('rolls the checks up to an overall status', function () {
    MpesaBalance::create(['type' => 'c2b', 'account_name' => 'Working Account', 'currency' => 'KES', 'amount' => 5000]);
    reconDeposit('R10', 30, 0);

    $report = reconcile($this);
    expect($report['status'])->toBe('warn');
    expect($report['counts'])->toBe(['pass' => 36, 'warn' => 1, 'fail' => 0]);

    $this->alice->update(['balance' => -1]);
    expect(reconcile($this)['status'])->toBe('fail');
});
