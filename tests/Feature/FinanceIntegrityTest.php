<?php

use App\Exceptions\MpesaApiException;
use App\Models\Customer;
use App\Models\GameTransaction;
use App\Models\GameWallet;
use App\Models\LedgerEntry;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Models\Withdraw;
use App\Services\LedgerService;
use App\Services\MpesaService;
use App\Services\WithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->apiKey = createApiKey('test-finance-integrity-key');

    $houseCustomer = Customer::factory()->create(['id' => 1]);
    $this->houseWallet = Wallet::forceCreate(['id' => 1, 'customer_id' => $houseCustomer->id, 'balance' => 0]);
});

function walletFor(float $balance): Wallet
{
    $customer = Customer::factory()->create();

    return Wallet::factory()->create(['customer_id' => $customer->id, 'balance' => $balance]);
}

function ledgerNet(string $walletType, int $walletId): float
{
    $entries = fn () => LedgerEntry::where('wallet_type', $walletType)->where('wallet_id', $walletId)->countable();

    return round((float) $entries()->sum('credit') - (float) $entries()->sum('debit'), 2);
}

// wallet_type + house cut traceability

it('tags ledger entries with the wallet table they point at', function () {
    $wallet = walletFor(200);
    $gameWallet = GameWallet::create(['game_id' => 'FIN_001', 'game_type' => 1, 'balance' => 0]);

    legacyGameCreditStake($wallet, $gameWallet, 100);

    expect(LedgerEntry::where('wallet_type', 'wallet')->where('wallet_id', $wallet->id)->where('entry_type', 'game_bet')->exists())->toBeTrue();
    expect(LedgerEntry::where('wallet_type', 'game_wallet')->where('wallet_id', $gameWallet->id)->where('entry_type', 'game_bet')->exists())->toBeTrue();
});

it('links house cuts to their source transaction and game', function () {
    $wallet = walletFor(200);
    $gameWallet = GameWallet::create(['game_id' => 'FIN_002', 'game_type' => 1, 'balance' => 0]);

    legacyGameCreditStake($wallet, $gameWallet, 100);

    $houseCut = LedgerEntry::where('entry_type', 'house_cut')->firstOrFail();

    expect($houseCut->referenceable_type)->toBe(GameTransaction::class);
    expect($houseCut->referenceable_id)->not->toBeNull();
    expect($houseCut->metadata)->toMatchArray(['source' => 'game_credit', 'game_wallet_id' => $gameWallet->id]);
    expect($houseCut->wallet_type)->toBe('wallet');
});

it('does not confuse a game wallet with a customer wallet that shares its id', function () {
    $wallet = walletFor(500);
    $gameWallet = GameWallet::forceCreate(['id' => $wallet->id, 'game_id' => 'FIN_COLLIDE', 'game_type' => 1, 'balance' => 0]);

    legacyGameCreditStake($wallet, $gameWallet, 100);

    expect(ledgerNet('wallet', $wallet->id))->toBe(-100.0);
    expect(ledgerNet('game_wallet', $gameWallet->id))->toBe(95.0);
});

// escrow releases

it('ledgers the escrow release when a game wallet is paid out', function () {
    $winner = walletFor(200);
    $gameWallet = GameWallet::create(['game_id' => 'FIN_003', 'game_type' => 1, 'balance' => 0]);

    legacyGameCreditStake($winner, $gameWallet, 100);

    $this->postJson('/api/v1/game/withdraw/'.encryptId($gameWallet->id), [
        'customer_id' => (string) $winner->customer_id,
    ], apiHeaders($this->apiKey->key))->assertStatus(201);

    expect((float) $gameWallet->fresh()->balance)->toBe(0.0);
    expect(ledgerNet('game_wallet', $gameWallet->id))->toBe(0.0);
    expect(LedgerEntry::where('entry_type', 'escrow_release')->where('wallet_id', $gameWallet->id)->count())->toBe(1);
});

it('ledgers the escrow release when a game wallet is fully refunded', function () {
    $player = walletFor(200);
    $gameWallet = GameWallet::create(['game_id' => 'FIN_004', 'game_type' => 1, 'balance' => 0]);

    legacyGameCreditStake($player, $gameWallet, 100);

    $this->postJson('/api/v1/game/refund', ['game_wallet_id' => $gameWallet->id], apiHeaders($this->apiKey->key))->assertOk();

    expect((float) $gameWallet->fresh()->balance)->toBe(0.0);
    expect(ledgerNet('game_wallet', $gameWallet->id))->toBe(0.0);
});

it('ledgers competition round transfers between competition wallets', function () {
    [$sender, $receiver] = createTournamentWalletPair(50);

    $this->postJson('/api/v1/competition/payout', [
        'sender_competition_wallet_id' => $sender->id,
        'receiver_competition_wallet_id' => $receiver->id,
    ], apiHeaders($this->apiKey->key))->assertOk();

    $out = LedgerEntry::where('entry_type', 'escrow_transfer')->where('wallet_id', $sender->id)->firstOrFail();
    $in = LedgerEntry::where('entry_type', 'escrow_transfer')->where('wallet_id', $receiver->id)->firstOrFail();

    expect($out->wallet_type)->toBe('competition_wallet');
    expect((float) $out->debit)->toBe(50.0);
    expect((float) $in->credit)->toBe(50.0);
    expect((float) $receiver->fresh()->balance)->toBe(50.0);
    expect((float) $sender->fresh()->balance)->toBe(0.0);
});

// manual adjustments

it('ledgers add, reduce and set balance with reason and actor', function () {
    $wallet = walletFor(100);
    $id = encryptId($wallet->id);
    $headers = apiHeaders($this->apiKey->key);

    $this->putJson("/api/v1/wallets/{$id}/deposit", ['amount' => 50, 'reason' => 'promo bonus'], $headers)->assertStatus(201);
    $this->putJson("/api/v1/wallets/{$id}/withdraw", ['amount' => 30, 'reason' => 'chargeback'], $headers)->assertStatus(201);
    $this->putJson("/api/v1/wallets/{$id}/balance", ['amount' => 500, 'reason' => 'migration fix'], $headers)->assertStatus(201);

    expect((float) $wallet->fresh()->balance)->toBe(500.0);

    $entries = LedgerEntry::where('entry_type', 'adjustment')->orderBy('id')->get();
    expect($entries)->toHaveCount(3);
    expect($entries->pluck('metadata.reason')->all())->toBe(['promo bonus', 'chargeback', 'migration fix']);
    expect($entries->pluck('metadata.actor')->unique()->all())->toBe(['api_key:'.$this->apiKey->id]);
    expect((float) $entries[0]->credit)->toBe(50.0);
    expect((float) $entries[1]->debit)->toBe(30.0);
    expect((float) $entries[2]->credit)->toBe(380.0);
    expect(ledgerNet('wallet', $wallet->id))->toBe(400.0);
});

it('falls back to an unspecified reason when none is sent', function () {
    $wallet = walletFor(100);

    $this->putJson('/api/v1/wallets/'.encryptId($wallet->id).'/deposit', ['amount' => 10], apiHeaders($this->apiKey->key))->assertStatus(201);

    expect(LedgerEntry::where('entry_type', 'adjustment')->firstOrFail()->metadata['reason'])->toBe('unspecified');
});

it('ledgers customer wallet updates and wallet PUT balance changes', function () {
    $wallet = walletFor(100);
    $headers = apiHeaders($this->apiKey->key);

    $this->putJson('/api/v1/customers/'.encryptId($wallet->customer_id).'/wallet', ['amount' => -25, 'reason' => 'correction'], $headers)->assertStatus(201);
    $this->putJson('/api/v1/wallets/'.encryptId($wallet->id), ['balance' => 200, 'reason' => 'audit'], $headers)->assertStatus(201);

    expect((float) $wallet->fresh()->balance)->toBe(200.0);
    expect(LedgerEntry::where('entry_type', 'adjustment')->count())->toBe(2);
    expect(ledgerNet('wallet', $wallet->id))->toBe(100.0);
});

it('refuses a customer wallet adjustment that would leave the balance below 0', function () {
    $wallet = walletFor(30);
    $headers = apiHeaders($this->apiKey->key);

    $this->putJson('/api/v1/customers/'.encryptId($wallet->customer_id).'/wallet', ['amount' => -31, 'reason' => 'clawback'], $headers)
        ->assertStatus(422)
        ->assertJson(['message' => 'Adjustment would leave the wallet balance below 0']);

    $this->putJson('/api/v1/customers/'.encryptId($wallet->customer_id).'/wallet', ['amount' => -30, 'reason' => 'clawback'], $headers)
        ->assertStatus(201);

    expect((float) $wallet->fresh()->balance)->toBe(0.0);
    expect(LedgerEntry::where('entry_type', 'adjustment')->count())->toBe(1);
});

it('allows a credit adjustment to a wallet that is already negative', function () {
    $wallet = walletFor(-50);

    $this->putJson('/api/v1/customers/'.encryptId($wallet->customer_id).'/wallet', ['amount' => 20, 'reason' => 'partial fix'], apiHeaders($this->apiKey->key))
        ->assertStatus(201);

    expect((float) $wallet->fresh()->balance)->toBe(-30.0);
});

it('sets a wallet balance to 0', function () {
    $wallet = walletFor(75);

    $this->putJson('/api/v1/wallets/'.encryptId($wallet->id).'/balance', ['amount' => 0, 'reason' => 'closing'], apiHeaders($this->apiKey->key))
        ->assertStatus(201)
        ->assertJson(['balance_after' => 0]);

    expect((float) $wallet->fresh()->balance)->toBe(0.0);
    expect(ledgerNet('wallet', $wallet->id))->toBe(-75.0);
});

it('writes no ledger entry when a rejected or no-op adjustment changes nothing', function () {
    $wallet = walletFor(100);
    $id = encryptId($wallet->id);
    $headers = apiHeaders($this->apiKey->key);

    $this->putJson("/api/v1/wallets/{$id}/withdraw", ['amount' => 500], $headers)->assertStatus(400);
    $this->putJson("/api/v1/wallets/{$id}/balance", ['amount' => 100], $headers)->assertStatus(201);

    expect(LedgerEntry::where('entry_type', 'adjustment')->count())->toBe(0);
});

// withdrawals

it('reverses the wallet debit when M-Pesa rejects a withdrawal', function () {
    $wallet = walletFor(200);
    $wallet->customer->update(['phone_no' => '254700000001']);

    $mpesa = Mockery::mock(MpesaService::class);
    $mpesa->shouldReceive('b2c')->once()->andThrow(new MpesaApiException('Service unavailable', 503));
    app()->instance(MpesaService::class, $mpesa);

    $result = app(WithdrawalService::class)->initiateWithdrawal((string) $wallet->customer_id, 80);

    expect($result['success'])->toBeFalse();
    expect((float) $wallet->fresh()->balance)->toBe(200.0);
    expect(ledgerNet('wallet', $wallet->id))->toBe(0.0);
    expect(LedgerEntry::where('entry_type', 'withdrawal')->firstOrFail()->status)->toBe('reversed');
    expect(LedgerEntry::where('entry_type', 'withdrawal_reversal')->count())->toBe(1);
});

it('does not debit the wallet when the customer has no phone number', function () {
    $wallet = walletFor(200);
    $wallet->customer->update(['phone_no' => null]);

    $result = app(WithdrawalService::class)->initiateWithdrawal((string) $wallet->customer_id, 80);

    expect($result['success'])->toBeFalse();
    expect((float) $wallet->fresh()->balance)->toBe(200.0);
    expect(LedgerEntry::count())->toBe(0);
});

it('reverses an accepted withdrawal when the B2C result callback reports failure, once', function () {
    $wallet = walletFor(200);
    $wallet->customer->update(['phone_no' => '254700000002']);

    $mpesa = Mockery::mock(MpesaService::class);
    $mpesa->shouldReceive('b2c')->once()->andReturn(['ResponseCode' => 0, 'ConversationID' => 'AG_FAIL_1']);
    app()->instance(MpesaService::class, $mpesa);

    expect(app(WithdrawalService::class)->initiateWithdrawal((string) $wallet->customer_id, 80)['success'])->toBeTrue();
    expect((float) $wallet->fresh()->balance)->toBe(120.0);

    $payload = ['Result' => [], 'ResultCode' => 2001, 'ResultDesc' => 'Initiator information is invalid', 'ConversationID' => 'AG_FAIL_1'];
    $this->postJson('/api/v1/b2c/result', $payload)->assertOk();

    expect((float) $wallet->fresh()->balance)->toBe(200.0);
    expect(Transaction::where('payment_type', Withdraw::class)->firstOrFail()->status)->toBe(3);
    expect(Withdraw::firstOrFail()->disburse)->toBe(3);

    // a redelivered callback must not credit the wallet twice
    $this->postJson('/api/v1/b2c/result', $payload)->assertOk();
    expect((float) $wallet->fresh()->balance)->toBe(200.0);
    expect(LedgerEntry::where('entry_type', 'withdrawal_reversal')->count())->toBe(1);
});

it('leaves an accepted withdrawal alone when the B2C result callback reports success', function () {
    $wallet = walletFor(200);
    $wallet->customer->update(['phone_no' => '254700000003']);

    $mpesa = Mockery::mock(MpesaService::class);
    $mpesa->shouldReceive('b2c')->once()->andReturn(['ResponseCode' => 0, 'ConversationID' => 'AG_OK_1']);
    app()->instance(MpesaService::class, $mpesa);

    app(WithdrawalService::class)->initiateWithdrawal((string) $wallet->customer_id, 80);

    $this->postJson('/api/v1/b2c/result', ['ResultCode' => 0, 'ResultDesc' => 'OK', 'ConversationID' => 'AG_OK_1', 'TransactionID' => 'LGR1'])->assertOk();

    expect((float) $wallet->fresh()->balance)->toBe(120.0);
    expect(LedgerEntry::where('entry_type', 'withdrawal_reversal')->count())->toBe(0);
});

// reconcile + backfill

it('reconciles a wallet that had a balance before the ledger began', function () {
    $wallet = walletFor(100);
    app(LedgerService::class)->recordDeposit(null, $wallet, 50.0);

    $this->artisan('wallet:reconcile')->assertSuccessful();
});

it('reports wallets whose balance drifted from the ledger', function () {
    $wallet = walletFor(100);
    app(LedgerService::class)->recordDeposit(null, $wallet, 50.0);
    $wallet->update(['balance' => 999]);

    $this->artisan('wallet:reconcile')->assertFailed();

    $this->artisan('wallet:reconcile', ['--fix' => true])->assertFailed();
    expect((float) $wallet->fresh()->balance)->toBe(150.0);
});

it('ignores game wallet entries that share an id with a customer wallet when reconciling', function () {
    $wallet = walletFor(500);
    $gameWallet = GameWallet::forceCreate(['id' => $wallet->id, 'game_id' => 'FIN_REC', 'game_type' => 1, 'balance' => 0]);

    legacyGameCreditStake($wallet, $gameWallet, 100);

    $this->artisan('wallet:reconcile', ['--wallet-id' => $wallet->id])->assertSuccessful();
});

it('counts a reversed entry and its reversal as netting to zero', function () {
    $wallet = walletFor(100);
    $ledger = app(LedgerService::class);
    $entry = $ledger->recordDeposit(null, $wallet, 50.0);
    $ledger->reverseEntry($entry);

    expect(ledgerNet('wallet', $wallet->id))->toBe(0.0);
    $this->artisan('wallet:reconcile')->assertSuccessful();
});

it('backfills wallet_type on legacy ledger entries and reports what it cannot classify', function () {
    $make = fn (array $attributes) => LedgerEntry::create($attributes + [
        'entry_id' => (string) Str::uuid(),
        'wallet_id' => 1,
        'debit' => 0,
        'credit' => 0,
        'balance_before' => 0,
        'balance_after' => 0,
        'status' => 'settled',
    ]);

    $deposit = $make(['entry_type' => 'deposit']);
    $gameSide = $make(['entry_type' => 'game_bet', 'metadata' => ['customer_wallet_id' => 3]]);
    $playerSide = $make(['entry_type' => 'game_bet', 'metadata' => ['game_wallet_id' => 3]]);
    $coinSide = $make(['entry_type' => 'coin_purchase', 'metadata' => ['amount_paid' => 40]]);
    $reversal = $make(['entry_type' => 'game_bet_reversal', 'metadata' => ['original_entry_id' => $gameSide->entry_id]]);
    $unknown = $make(['entry_type' => 'mystery']);

    $this->artisan('ledger:backfill-wallet-type', ['--dry-run' => true])->assertFailed();
    expect(LedgerEntry::whereNull('wallet_type')->count())->toBe(6);

    $this->artisan('ledger:backfill-wallet-type')->assertFailed();

    expect($deposit->fresh()->wallet_type)->toBe('wallet');
    expect($gameSide->fresh()->wallet_type)->toBe('game_wallet');
    expect($playerSide->fresh()->wallet_type)->toBe('wallet');
    expect($coinSide->fresh()->wallet_type)->toBe('coin_wallet');
    expect($reversal->fresh()->wallet_type)->toBe('game_wallet');
    expect($unknown->fresh()->wallet_type)->toBeNull();
});

// test customer rule

it('excludes the configured test customers from the customer scope', function () {
    config(['finance.test_customer_ids' => [900001, 900002]]);

    $test = Customer::factory()->create(['id' => 900001]);
    $real = Customer::factory()->create(['id' => 900003]);

    $ids = Customer::excludingTest()->pluck('id');

    expect($ids)->not->toContain($test->id);
    expect($ids)->toContain($real->id);
});

it('has no test customers by default', function () {
    expect(config('finance.test_customer_ids'))->toBe([]);
});

it('leaves every customer in the customer scope when there are no test customers', function () {
    config(['finance.test_customer_ids' => []]);

    $customer = Customer::factory()->create(['id' => 5]);

    expect(Customer::excludingTest()->pluck('id'))->toContain($customer->id);
});

// wallet_version (webhook balance_version source)

it('increments wallet_version by exactly 1 when balance changes', function () {
    $wallet = walletFor(100);
    expect((int) $wallet->wallet_version)->toBe(0);

    $wallet->update(['balance' => 150]);

    expect((int) $wallet->fresh()->wallet_version)->toBe(1);
});

it('does not move wallet_version when balance is untouched', function () {
    $wallet = walletFor(100);

    $wallet->touch();

    expect((int) $wallet->fresh()->wallet_version)->toBe(0);
});

it('records the house cut against the house wallet owner', function () {
    $owner = Customer::factory()->create(['id' => 7]);
    $ownerHouseWallet = Wallet::factory()->create(['customer_id' => $owner->id, 'balance' => 0]);
    config(['wallets.house_wallet_id' => $ownerHouseWallet->id]);

    $player = walletFor(500);
    $gameWallet = GameWallet::create(['game_id' => 'FIN_HOUSE_OWNER', 'game_type' => 1, 'balance' => 0, 'status' => 1]);

    $this->postJson('/api/v1/game/bets', [
        'game_wallet_id' => $gameWallet->id,
        'customer_id' => $player->customer_id,
        'payment_type' => 'deposit',
        'amount' => 100,
        'status' => 1,
    ], apiHeaders($this->apiKey->key))->assertCreated();

    $this->postJson('/api/v1/game/withdraw/'.encryptId($gameWallet->id), [
        'customer_id' => (string) $player->customer_id,
    ], apiHeaders($this->apiKey->key))->assertCreated();

    $cuts = LedgerEntry::where('entry_type', 'house_cut')->get();

    expect($cuts)->not->toBeEmpty()
        ->and($cuts->pluck('customer_id')->unique()->values()->all())->toBe([7])
        ->and($cuts->pluck('wallet_id')->unique()->values()->all())->toBe([$ownerHouseWallet->id]);
});
