<?php

use App\Models\Coin;
use App\Models\CompetitionWallet;
use App\Models\Customer;
use App\Models\GameTransaction;
use App\Models\GameWallet;
use App\Models\LedgerEntry;
use App\Models\PendingBalance;
use App\Models\Referral;
use App\Models\ReferralCode;
use App\Models\ReferralWallet;
use App\Models\ReferralWithdrawal;
use App\Models\Wallet;
use App\Models\Withdraw;
use App\Services\CustomerService;
use App\Services\ReferralService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->customerService = app(CustomerService::class);
    config(['wallets.house_wallet_id' => 999999]);
});

it('soft-deletes a customer with nothing open and keeps the wallet row', function () {
    $customer = Customer::factory()->create();
    $wallet = Wallet::factory()->create(['customer_id' => $customer->id, 'balance' => 0]);

    $result = $this->customerService->deleteCustomer($customer->id);

    expect($result['success'])->toBeTrue()
        ->and($result['status_code'])->toBe(200)
        ->and(Customer::find($customer->id))->toBeNull()
        ->and(Customer::withTrashed()->find($customer->id)->trashed())->toBeTrue()
        ->and(Wallet::find($wallet->id))->not->toBeNull()
        ->and(Wallet::find($wallet->id)->customer->id)->toBe($customer->id);
});

it('returns 404 for a nonexistent customer', function () {
    $result = $this->customerService->deleteCustomer(9999);

    expect($result['success'])->toBeFalse()
        ->and($result['status_code'])->toBe(404);
});

it('refuses to delete a customer whose wallet holds money', function () {
    $customer = Customer::factory()->create();
    $wallet = Wallet::factory()->create(['customer_id' => $customer->id, 'balance' => 500]);

    $result = $this->customerService->deleteCustomer($customer->id);

    expect($result['status_code'])->toBe(409)
        ->and($result['open'])->toBe(['wallet_balance'])
        ->and(Customer::find($customer->id))->not->toBeNull()
        ->and((float) Wallet::find($wallet->id)->balance)->toBe(500.0);
});

it('refuses to delete a customer with a negative wallet balance', function () {
    $customer = Customer::factory()->create();
    Wallet::factory()->create(['customer_id' => $customer->id, 'balance' => -20]);

    expect($this->customerService->openObligations($customer))->toBe(['wallet_balance']);
});

it('refuses to delete the house customer', function () {
    $customer = Customer::factory()->create();
    $wallet = Wallet::factory()->create(['customer_id' => $customer->id, 'balance' => 0]);
    config(['wallets.house_wallet_id' => $wallet->id]);

    expect($this->customerService->openObligations($customer))->toBe(['house_wallet']);
});

it('refuses to delete a customer with coins or a referral balance', function () {
    $customer = Customer::factory()->create();
    Wallet::factory()->create(['customer_id' => $customer->id, 'balance' => 0]);
    Coin::create(['customer_id' => $customer->id, 'coins' => 10, 'status' => 1]);
    ReferralWallet::create(['customer_id' => $customer->id, 'balance' => 50]);

    expect($this->customerService->openObligations($customer))->toBe(['coin_balance', 'referral_balance']);
});

it('refuses to delete a customer with a stake in an unfinished game', function () {
    $customer = Customer::factory()->create();
    Wallet::factory()->create(['customer_id' => $customer->id, 'balance' => 0]);
    $game = GameWallet::create(['game_id' => 'G-OPEN', 'game_type' => 1, 'balance' => 40, 'status' => 1]);
    GameTransaction::create(['game_wallet_id' => $game->id, 'customer_id' => $customer->id, 'payment_type' => 'bet', 'amount' => 20, 'status' => 2]);

    expect($this->customerService->openObligations($customer))->toBe(['open_game']);

    $game->update(['balance' => 0, 'status' => 3]);

    expect($this->customerService->openObligations($customer))->toBe([]);
});

it('refuses to delete a customer in an open competition', function () {
    $customer = Customer::factory()->create();
    Wallet::factory()->create(['customer_id' => $customer->id, 'balance' => 0]);
    CompetitionWallet::create([
        'competition_id' => 'TOURN-1', 'cmp_uid' => '123', 'game_type' => 1, 'customer_id' => $customer->id,
        'level' => 1, 'jp_rounds' => 5, 'balance' => 0, 'status' => 1,
    ]);

    expect($this->customerService->openObligations($customer))->toBe(['open_competition']);
});

it('refuses to delete a customer whose withdrawal is waiting on the B2C result', function () {
    $customer = Customer::factory()->create();
    $wallet = Wallet::factory()->create(['customer_id' => $customer->id, 'balance' => 0]);
    $transaction = $wallet->transactions()->create(['payment_type' => Withdraw::class, 'amount' => 100, 'status' => 2, 'payment_ref' => 'AG_CONV_1']);
    $withdraw = Withdraw::create(['transaction_id' => $transaction->id, 'amount' => 100, 'disburse' => 2, 'receipt' => 'AG_CONV_1']);
    $transaction->update(['payment_id' => $withdraw->id]);

    expect($this->customerService->openObligations($customer))->toBe(['pending_withdrawal']);

    $transaction->update(['payment_ref' => 'SFC123RECEIPT']);

    expect($this->customerService->openObligations($customer))->toBe([]);
});

it('refuses to delete a customer with a pending referral withdrawal or a held balance', function () {
    $customer = Customer::factory()->create();
    $wallet = Wallet::factory()->create(['customer_id' => $customer->id, 'balance' => 0]);
    $referralWallet = ReferralWallet::create(['customer_id' => $customer->id, 'balance' => 0]);
    ReferralWithdrawal::create([
        'customer_id' => $customer->id, 'referral_wallet_id' => $referralWallet->id, 'amount' => 50,
        'phone_no' => '254700000000', 'status' => ReferralWithdrawal::STATUS_PROCESSING,
    ]);
    PendingBalance::create([
        'pending_id' => 'hold-1', 'wallet_id' => $wallet->id, 'customer_id' => $customer->id,
        'amount' => 20, 'type' => 'game_stake', 'status' => 'holding', 'expires_at' => now()->addDay(),
    ]);

    expect($this->customerService->openObligations($customer))->toBe(['pending_referral_withdrawal', 'pending_hold']);
});

it('answers the delete endpoint with 409 and the open items', function () {
    $apiKey = createApiKey();
    $customer = Customer::factory()->create();
    Wallet::factory()->create(['customer_id' => $customer->id, 'balance' => 500]);

    $this->deleteJson('/api/v1/customers/'.encryptId($customer->id), [], apiHeaders($apiKey->key))
        ->assertStatus(409)
        ->assertJson(['open' => ['wallet_balance']])
        ->assertJsonMissingPath('status_code');
});

it('keeps ledger history pointing at a deleted customer', function () {
    $customer = Customer::factory()->create();
    $wallet = Wallet::factory()->create(['customer_id' => $customer->id, 'balance' => 0]);
    $entry = LedgerEntry::create([
        'entry_id' => 'entry-1', 'entry_type' => 'deposit', 'wallet_type' => 'wallet', 'wallet_id' => $wallet->id,
        'customer_id' => $customer->id, 'debit' => 0, 'credit' => 10, 'balance_before' => 0, 'balance_after' => 10, 'status' => 'completed',
    ]);

    $this->customerService->deleteCustomer($customer->id);

    expect($entry->fresh()->customer?->id)->toBe($customer->id);
});

it('stops a deleted customer earning referrals', function () {
    config(['referrals.enabled' => true, 'referrals.effective_from' => null]);
    $referrer = Customer::factory()->create();
    ReferralCode::create(['customer_id' => $referrer->id, 'code' => 'GONE1']);
    $earlier = Customer::factory()->create();
    $referral = Referral::create(['referrer_id' => $referrer->id, 'referred_id' => $earlier->id, 'code_used' => 'GONE1']);

    $this->customerService->deleteCustomer($referrer->id);

    $referrals = app(ReferralService::class);
    $newcomer = Customer::factory()->create();

    expect($referrals->attachAtSignup($newcomer, 'GONE1'))->toBeNull()
        ->and($referrals->markVerified($earlier)->bonuses)->toBeEmpty()
        ->and(ReferralWallet::where('customer_id', $referrer->id)->exists())->toBeFalse()
        ->and($referral->fresh()->verified_at)->not->toBeNull();
});
