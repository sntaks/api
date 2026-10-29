<?php

use App\Models\Customer;
use App\Models\GameTransaction;
use App\Models\GameWallet;
use App\Models\LedgerEntry;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->apiKey = createApiKey('test-game-withdraw-key');
});

it('game withdraw returns 404 for nonexistent wallet', function () {
    $encryptedId = encryptId(9999);

    $response = $this->postJson("/api/v1/game/withdraw/{$encryptedId}", [
        'customer_id' => '1',
    ], apiHeaders($this->apiKey->key));

    $response->assertStatus(404);
});

it('never takes more than the house rate from a game payout', function (float $pot, float $houseShare) {
    $house = Customer::factory()->create(['id' => 1]);
    $houseWallet = Wallet::forceCreate(['id' => 1, 'customer_id' => $house->id, 'balance' => 0]);
    $winner = Customer::factory()->create();
    $winnerWallet = Wallet::factory()->create(['customer_id' => $winner->id, 'balance' => 0]);
    $gameWallet = GameWallet::create(['game_id' => 'GAME_WD_POT', 'game_type' => 1, 'balance' => $pot, 'status' => 1]);
    // The pot is set directly so the house share stays exact; the winner still has to have played.
    GameTransaction::create(['game_wallet_id' => $gameWallet->id, 'customer_id' => $winner->id, 'amount' => $pot, 'payment_type' => 'deposit', 'status' => 2]);

    $this->postJson('/api/v1/game/withdraw/'.encryptId($gameWallet->id), [
        'customer_id' => (string) $winner->id,
    ], apiHeaders($this->apiKey->key))->assertStatus(201);

    expect((float) $houseWallet->fresh()->balance)->toBe($houseShare)
        ->and((float) $winnerWallet->fresh()->balance)->toBe($pot - $houseShare)
        ->and((float) $gameWallet->fresh()->balance)->toBe(0.0);
})->with([
    'pot of 1 pays the house nothing' => [1, 0],
    'pot of 50 pays the house 2, not 3' => [50, 2],
    'pot of 100 pays the house 5' => [100, 5],
]);

it('validates customer_id required for game withdraw', function () {
    $gw = GameWallet::create([
        'game_id' => 'GAME_WD_001',
        'game_type' => 1,
        'balance' => 100,
    ]);
    $encryptedId = encryptId($gw->id);

    $response = $this->postJson("/api/v1/game/withdraw/{$encryptedId}", [], apiHeaders($this->apiKey->key));

    $response->assertStatus(422);
});

it('refuses to pay a customer who did not stake in the game', function () {
    $house = Customer::factory()->create(['id' => 1]);
    Wallet::forceCreate(['id' => 1, 'customer_id' => $house->id, 'balance' => 0]);
    [$alice, $bob, $stranger] = collect(range(1, 3))->map(function () {
        $customer = Customer::factory()->create();
        Wallet::factory()->create(['customer_id' => $customer->id, 'balance' => 500]);

        return $customer;
    })->all();
    $game = GameWallet::create(['game_id' => 'GAME_WD_STRANGER', 'game_type' => 1, 'balance' => 0, 'status' => 1]);

    foreach ([$alice, $bob] as $player) {
        $this->postJson('/api/v1/game/bets', [
            'game_wallet_id' => $game->id,
            'customer_id' => $player->id,
            'payment_type' => 'deposit',
            'amount' => 100,
            'status' => 1,
        ], apiHeaders($this->apiKey->key))->assertCreated();
    }
    $pot = (float) $game->fresh()->balance;

    $this->postJson('/api/v1/game/withdraw/'.encryptId($game->id), [
        'customer_id' => (string) $stranger->id,
    ], apiHeaders($this->apiKey->key))
        ->assertStatus(422)
        ->assertJsonPath('code', 'not_a_player');

    expect((float) $game->fresh()->balance)->toBe($pot)
        ->and($game->fresh()->status)->toBe(1)
        ->and(LedgerEntry::where('entry_type', 'game_payout')->count())->toBe(0)
        ->and((float) $stranger->wallet->fresh()->balance)->toBe(500.0);
});
