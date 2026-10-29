<?php

use App\Models\Customer;
use App\Models\GameWallet;
use App\Models\LedgerEntry;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->apiKey = createApiKey('test-game-txn-key');
    $this->customer = Customer::factory()->create(['id_no' => 'GTxn001']);
    $this->gameWallet = GameWallet::create([
        'game_id' => 'GAME_TXN_001',
        'game_type' => 1,
        'balance' => 1000,
    ]);
});

it('validates required fields for game bet creation', function () {
    $response = $this->postJson('/api/v1/game/bets', [], apiHeaders($this->apiKey->key));

    $response->assertStatus(422);
});

it('lists game bets', function () {
    $response = $this->getJson('/api/v1/game/bets', apiHeaders($this->apiKey->key));

    $response->assertOk();
});

it('refuses a zero, negative or sub-cent stake', function (float $amount) {
    $wallet = Wallet::factory()->create(['customer_id' => $this->customer->id, 'balance' => 500]);

    $this->postJson('/api/v1/game/bets', [
        'game_wallet_id' => $this->gameWallet->id,
        'customer_id' => $this->customer->id,
        'payment_type' => 'deposit',
        'amount' => $amount,
        'status' => 1,
    ], apiHeaders($this->apiKey->key))->assertStatus(422)->assertJsonValidationErrors('amount');

    expect((float) $wallet->fresh()->balance)->toBe(500.0)
        ->and((float) $this->gameWallet->fresh()->balance)->toBe(1000.0)
        ->and(LedgerEntry::where('entry_type', 'game_bet')->count())->toBe(0);
})->with([0, -50, 10.555]);

it('refuses a stake without a customer', function () {
    $this->postJson('/api/v1/game/bets', [
        'game_wallet_id' => $this->gameWallet->id,
        'payment_type' => 'deposit',
        'amount' => 100,
        'status' => 1,
    ], apiHeaders($this->apiKey->key))->assertStatus(422)->assertJsonValidationErrors('customer_id');
});
