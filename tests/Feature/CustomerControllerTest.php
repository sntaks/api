<?php

use App\Models\CompetitionWallet;
use App\Models\Customer;
use App\Models\GameTransaction;
use App\Models\GameWallet;
use App\Services\CompetitionPayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->apiKey = createApiKey('test-customer-key');
});

it('lists customers', function () {
    Customer::factory()->create(['id_no' => 'CUS001']);

    $response = $this->getJson('/api/v1/customers', apiHeaders($this->apiKey->key));

    $response->assertOk();
});

it('creates a customer', function () {
    $response = $this->postJson('/api/v1/customers', [
        'account_no' => 'ACC_NEW_001',
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'id_no' => '12345',
        'phone_no' => '254712345678',
    ], apiHeaders($this->apiKey->key));

    $response->assertStatus(201);
    $response->assertJson(['status' => 'Success']);
});

it('validates required fields for customer creation', function () {
    $response = $this->postJson('/api/v1/customers', [], apiHeaders($this->apiKey->key));

    $response->assertStatus(422);
});

it('shows a specific customer', function () {
    $customer = Customer::factory()->create(['id_no' => 'CUS_SHOW_001']);
    $encryptedId = encryptId($customer->id);

    $response = $this->getJson("/api/v1/customers/{$encryptedId}", apiHeaders($this->apiKey->key));

    $response->assertOk();
});

it('returns 404 for nonexistent customer', function () {
    $encryptedId = encryptId(9999);

    $response = $this->getJson("/api/v1/customers/{$encryptedId}", apiHeaders($this->apiKey->key));

    $response->assertStatus(404);
});

it('updates a customer', function () {
    $customer = Customer::factory()->create(['id_no' => 'CUS_UPD_001']);
    $encryptedId = encryptId($customer->id);

    $response = $this->putJson("/api/v1/customers/{$encryptedId}", [
        'name' => 'Updated Name',
    ], apiHeaders($this->apiKey->key));

    $response->assertOk();
});

it('deletes a customer', function () {
    $customer = Customer::factory()->create(['id_no' => 'CUS_DEL_001']);
    $encryptedId = encryptId($customer->id);

    $response = $this->deleteJson("/api/v1/customers/{$encryptedId}", [], apiHeaders($this->apiKey->key));

    $response->assertOk();
    $response->assertJson(['message' => 'Customer deleted successfully']);
});

it('searches customers by query', function () {
    Customer::factory()->create(['id_no' => 'SEARCH001', 'name' => 'Alice Searchable']);

    $response = $this->getJson('/api/v1/customers/search?q=Alice', apiHeaders($this->apiKey->key));

    $response->assertOk();
});

it('returns customer games played', function () {
    $customer = Customer::factory()->create(['id_no' => 'CPLAY001']);
    $encryptedId = encryptId($customer->id);

    $response = $this->getJson("/api/v1/customers/played/{$encryptedId}", apiHeaders($this->apiKey->key));

    $response->assertOk();
    $response->assertJsonStructure(['single_games', 'tournament_games', 'jackpot_games']);
});

/**
 * A single game the customer staked in, optionally paid out to them.
 */
function playedSingleGame(Customer $customer, bool $won = false): GameWallet
{
    $game = GameWallet::create(['game_id' => 'GAME-'.fake()->unique()->numberBetween(1, 999999), 'balance' => 0, 'status' => 1]);
    GameTransaction::create(['game_wallet_id' => $game->id, 'customer_id' => $customer->id, 'payment_type' => 'deposit', 'amount' => 100, 'status' => 1]);

    if ($won) {
        GameTransaction::create(['game_wallet_id' => $game->id, 'customer_id' => $customer->id, 'payment_type' => 'payout', 'amount' => 180, 'status' => 1]);
    }

    return $game;
}

/**
 * A competition wallet for the customer; `cmpUid` groups the players of one competition.
 */
function playedCompetition(Customer $customer, int $gameType, ?string $cmpUid = null): CompetitionWallet
{
    return CompetitionWallet::create([
        'competition_id' => ($gameType === 1 ? 'TOURN-' : 'JP-').fake()->unique()->numberBetween(1, 999999),
        'cmp_uid' => $cmpUid ?? (string) fake()->unique()->numberBetween(1000, 999999),
        'game_type' => $gameType,
        'customer_id' => $customer->id,
        'level' => 1,
        'jp_rounds' => 5,
        'balance' => 50,
        'status' => 1,
    ]);
}

it('returns the latest 10 single games, tournaments and jackpots newest first', function () {
    $customer = Customer::factory()->create();
    $games = collect(range(1, 12))->map(fn () => playedSingleGame($customer));
    $tournaments = collect(range(1, 12))->map(fn () => playedCompetition($customer, 1));
    $jackpots = collect(range(1, 11))->map(fn () => playedCompetition($customer, 2));

    $response = $this->getJson('/api/v1/customers/played/recent/'.encryptId($customer->id), apiHeaders($this->apiKey->key));

    $response->assertOk()
        ->assertJsonCount(10, 'single_games')
        ->assertJsonCount(10, 'tournament_games')
        ->assertJsonCount(10, 'jackpot_games')
        ->assertJsonPath('single_games.0.game_wallet_id', $games->last()->id)
        ->assertJsonPath('single_games.0.game_id', $games->last()->game_id)
        ->assertJsonPath('tournament_games.0.competition_wallet_id', $tournaments->last()->id)
        ->assertJsonPath('tournament_games.0.competition_id', $tournaments->last()->competition_id)
        ->assertJsonPath('jackpot_games.0.competition_wallet_id', $jackpots->last()->id)
        ->assertJsonPath('jackpot_games.0.competition_id', $jackpots->last()->competition_id);

    expect(collect($response->json('single_games'))->pluck('game_wallet_id'))->not->toContain($games->first()->id);
});

it('lists a single game once with the customer stake, player count and result', function () {
    $customer = Customer::factory()->create();
    $opponent = Customer::factory()->create();
    $game = playedSingleGame($customer, won: true);
    GameTransaction::create(['game_wallet_id' => $game->id, 'customer_id' => $opponent->id, 'payment_type' => 'deposit', 'amount' => 100, 'status' => 1]);
    playedSingleGame($customer);

    $response = $this->getJson('/api/v1/customers/played/recent/'.encryptId($customer->id), apiHeaders($this->apiKey->key));

    $response->assertOk()->assertJsonCount(2, 'single_games');
    $row = collect($response->json('single_games'))->firstWhere('game_wallet_id', $game->id);

    expect($row)->toMatchArray(['game_id' => $game->game_id, 'players' => 2, 'amount' => 100, 'state' => 'win'])
        ->and(collect($response->json('single_games'))->firstWhere('game_wallet_id', '!=', $game->id)['state'])->toBe('loss');
});

it('pairs every tournament round with the opponent who played it', function () {
    $customer = Customer::factory()->create();
    $mine = playedCompetition($customer, 1, 'CMP-SHARED');
    $beatMe = playedCompetition(Customer::factory()->create(), 1, 'CMP-SHARED');
    $lostToMe = playedCompetition(Customer::factory()->create(), 1, 'CMP-SHARED');
    playedCompetition(Customer::factory()->create(), 1, 'CMP-SHARED');
    $payouts = app(CompetitionPayoutService::class);
    $payouts->processPayout($mine->id, $beatMe->id);
    $payouts->processPayout($lostToMe->id, $mine->id);

    $response = $this->getJson('/api/v1/customers/played/recent/'.encryptId($customer->id), apiHeaders($this->apiKey->key));

    $winnersWin = $beatMe->transactions()->where('payment_type', 'win')->value('id');
    $losersLoss = $lostToMe->transactions()->where('payment_type', 'loss')->value('id');

    $response->assertOk()
        ->assertJsonCount(1, 'tournament_games')
        ->assertJsonMissingPath('tournament_games.0.opponents')
        ->assertJsonPath('tournament_games.0.competition_wallet_id', $mine->id)
        ->assertJsonPath('tournament_games.0.games.0.payment_type', 'win')
        ->assertJsonPath('tournament_games.0.games.0.opponent', [
            'competition_wallet_id' => $lostToMe->id, 'customer_id' => $lostToMe->customer_id, 'wallet_status' => 1, 'transaction_id' => $losersLoss,
        ])
        ->assertJsonPath('tournament_games.0.games.1.payment_type', 'loss')
        ->assertJsonPath('tournament_games.0.games.1.opponent', [
            'competition_wallet_id' => $beatMe->id, 'customer_id' => $beatMe->customer_id, 'wallet_status' => 1, 'transaction_id' => $winnersWin,
        ]);
});

it('gives what is needed to file a complaint about a lost round', function () {
    $customer = Customer::factory()->create();
    $mine = playedCompetition($customer, 1, 'CMP-DISPUTE');
    $winner = playedCompetition(Customer::factory()->create(), 1, 'CMP-DISPUTE');
    app(CompetitionPayoutService::class)->processPayout($mine->id, $winner->id);

    $opponent = $this->getJson('/api/v1/customers/played/recent/'.encryptId($customer->id), apiHeaders($this->apiKey->key))
        ->json('tournament_games.0.games.0.opponent');

    $this->postJson('/api/v1/complaints', [
        'customer_id' => $customer->id,
        'competition_wallet_id' => $opponent['competition_wallet_id'],
        'transaction_ids' => [$opponent['transaction_id']],
        'reason' => 'The winner used a bot',
    ], apiHeaders($this->apiKey->key))
        ->assertCreated()
        ->assertJsonPath('data.competition_wallet_id', $winner->id)
        ->assertJsonPath('data.disputed_transactions.0.transaction_id', $opponent['transaction_id']);
});

it('shows the win or loss of every round played in a competition, newest first', function () {
    [$mine, $theirs] = createJackpotWalletPair();
    $payouts = app(CompetitionPayoutService::class);
    $payouts->processPayout($mine->id, $theirs->id);
    $payouts->processPayout($theirs->id, $mine->id);

    $response = $this->getJson('/api/v1/customers/played/recent/'.encryptId($mine->customer_id), apiHeaders($this->apiKey->key));

    $response->assertOk()
        ->assertJsonCount(1, 'jackpot_games')
        ->assertJsonPath('jackpot_games.0.wins', 1)
        ->assertJsonPath('jackpot_games.0.losses', 1)
        ->assertJsonCount(2, 'jackpot_games.0.games')
        ->assertJsonPath('jackpot_games.0.games.0.payment_type', 'win')
        ->assertJsonPath('jackpot_games.0.games.1.payment_type', 'loss')
        ->assertJsonPath('jackpot_games.0.games.0.opponent.competition_wallet_id', $theirs->id)
        ->assertJsonPath('jackpot_games.0.games.1.opponent.competition_wallet_id', $theirs->id);
});

it('excludes games other customers played', function () {
    $customer = Customer::factory()->create();
    $other = Customer::factory()->create();
    playedSingleGame($other);
    playedCompetition($other, 1);
    playedCompetition($other, 2);

    $response = $this->getJson('/api/v1/customers/played/recent/'.encryptId($customer->id), apiHeaders($this->apiKey->key));

    $response->assertOk()
        ->assertJsonCount(0, 'single_games')
        ->assertJsonCount(0, 'tournament_games')
        ->assertJsonCount(0, 'jackpot_games');
});

it('returns 404 for recent games of an unknown customer', function () {
    $response = $this->getJson('/api/v1/customers/played/recent/'.encryptId(9999), apiHeaders($this->apiKey->key));

    $response->assertNotFound();
});

it('returns customer purchases', function () {
    $customer = Customer::factory()->create(['id_no' => 'CPUR001']);
    $encryptedId = encryptId($customer->id);

    $response = $this->getJson("/api/v1/customers/purchases/{$encryptedId}", apiHeaders($this->apiKey->key));

    $response->assertOk();
});

it('clears phone verification when the phone changes', function () {
    $customer = Customer::factory()->create(['phone_no' => '254712345678']);
    $customer->forceFill(['phone_no_verified_at' => now()])->save();

    $this->putJson('/api/v1/customers/'.encryptId($customer->id), [
        'phone_no' => '254722000111',
    ], apiHeaders($this->apiKey->key))->assertOk();

    expect($customer->fresh()->phone_no_verified_at)->toBeNull();
});

it('keeps phone verification when the same phone is reformatted', function () {
    $customer = Customer::factory()->create(['phone_no' => '254712345678']);
    $customer->forceFill(['phone_no_verified_at' => now()])->save();

    $this->putJson('/api/v1/customers/'.encryptId($customer->id), [
        'phone_no' => '0712345678',
    ], apiHeaders($this->apiKey->key))->assertOk();

    expect($customer->fresh()->phone_no_verified_at)->not->toBeNull();
});

it('refuses an account number that is a player number', function () {
    $other = Customer::factory()->create();
    $customer = Customer::factory()->create();

    $this->putJson('/api/v1/customers/'.encryptId($customer->id), [
        'account_no' => $other->player_no,
    ], apiHeaders($this->apiKey->key))->assertStatus(422)->assertJsonValidationErrors('account_no');

    $this->postJson('/api/v1/customers', [
        'account_no' => $other->player_no,
        'name' => 'New Player',
        'email' => 'new.player@example.com',
    ], apiHeaders($this->apiKey->key))->assertStatus(422)->assertJsonValidationErrors('account_no');
});
