<?php

use App\Models\CompetitionTransaction;
use App\Models\CompetitionWallet;
use App\Models\Customer;
use App\Models\GameTransaction;
use App\Models\GameWallet;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->apiKey = createApiKey('test-list-pagination-key');

    $customer = Customer::factory()->create();
    $wallet = Wallet::factory()->create(['customer_id' => $customer->id]);
    $game = GameWallet::create(['game_id' => 'G-PAGE', 'game_type' => 1, 'balance' => 0, 'status' => 1]);
    $competition = CompetitionWallet::create([
        'competition_id' => 'C-PAGE', 'cmp_uid' => '777', 'game_type' => 1, 'customer_id' => $customer->id,
        'level' => 1, 'jp_rounds' => 3, 'balance' => 0, 'status' => 1,
    ]);

    foreach (range(1, 2) as $i) {
        GameWallet::create(['game_id' => "G-PAGE-{$i}", 'game_type' => 1, 'balance' => 0, 'status' => 1]);
        CompetitionWallet::create([
            'competition_id' => 'C-PAGE', 'cmp_uid' => "77{$i}", 'game_type' => 1, 'customer_id' => $customer->id,
            'level' => 1, 'jp_rounds' => 3, 'balance' => 0, 'status' => 1,
        ]);
    }

    foreach (range(1, 3) as $i) {
        GameTransaction::create(['game_wallet_id' => $game->id, 'customer_id' => $customer->id, 'payment_type' => 'deposit', 'amount' => $i, 'status' => 2]);
        CompetitionTransaction::create(['competition_wallet_id' => $competition->id, 'customer_id' => $customer->id, 'payment_type' => 'deposit', 'level' => 1, 'amount' => $i, 'status' => 2]);
        WalletTransaction::create(['transaction_type' => 'w2w', 'sender_id' => $wallet->id, 'receiver_id' => $wallet->id, 'initiator_id' => $customer->id, 'amount' => $i]);
    }
});

dataset('list endpoints', [
    'game wallets' => ['/api/v1/game/wallets', GameWallet::class],
    'game bets' => ['/api/v1/game/bets', GameTransaction::class],
    'competition wallets' => ['/api/v1/competition/wallets', CompetitionWallet::class],
    'competition transactions' => ['/api/v1/competition/transactions', CompetitionTransaction::class],
    'wallet transactions' => ['/api/v1/wallets/transactions', WalletTransaction::class],
]);

it('lists everything without per_page, as before', function (string $endpoint) {
    $this->getJson($endpoint, apiHeaders($this->apiKey->key))
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonMissingPath('meta');
})->with('list endpoints');

it('returns one page, newest first, when per_page is sent', function (string $endpoint, string $model) {
    $response = $this->getJson("{$endpoint}?per_page=2&page=1", apiHeaders($this->apiKey->key))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.per_page', 2);

    expect($response->json('data.0.id'))->toBe($model::max('id'));

    $this->getJson("{$endpoint}?per_page=2&page=2", apiHeaders($this->apiKey->key))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $model::min('id'));
})->with('list endpoints');

it('rejects a page size outside 1 to 200', function (string $endpoint) {
    $this->getJson("{$endpoint}?per_page=201", apiHeaders($this->apiKey->key))->assertStatus(422);
    $this->getJson("{$endpoint}?per_page=0", apiHeaders($this->apiKey->key))->assertStatus(422);
})->with('list endpoints');
