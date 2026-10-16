<?php

use App\Models\CompetitionWallet;
use App\Models\Customer;
use App\Models\GameTransaction;
use App\Models\GameWallet;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->apiKey = createApiKey('test-stats-key');
    Carbon::setTestNow('2026-10-16 12:00:00');

    $house = Customer::factory()->create(['id' => 1]);
    Wallet::forceCreate(['id' => 1, 'customer_id' => $house->id, 'balance' => 0]);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * A single game whose players all staked on $day, and the house payout taken from it on $day.
 */
function singleGameOn(string $day, int $players, float $houseCut, int $payoutCustomerId = 1): void
{
    $game = GameWallet::create(['game_id' => 'G-'.fake()->unique()->numberBetween(1, 99999), 'game_type' => 1, 'balance' => 0, 'status' => 3]);

    foreach (range(1, $players) as $ignored) {
        GameTransaction::forceCreate(['game_wallet_id' => $game->id, 'customer_id' => Customer::factory()->create()->id, 'payment_type' => 'deposit', 'amount' => 20, 'status' => 2, 'created_at' => $day]);
    }

    GameTransaction::forceCreate(['game_wallet_id' => $game->id, 'customer_id' => $payoutCustomerId, 'payment_type' => 'payout', 'amount' => $houseCut, 'status' => 2, 'created_at' => $day]);
}

/**
 * A competition wallet created on $day with one house share (c2w) taken from it.
 */
function competitionOn(string $day, int $gameType, int $rounds, float $houseShare, string $transactionType = 'c2w'): void
{
    $competition = CompetitionWallet::forceCreate([
        'competition_id' => 'C-1', 'cmp_uid' => (string) fake()->unique()->numberBetween(1, 99999), 'game_type' => $gameType,
        'customer_id' => 1, 'level' => 1, 'jp_rounds' => $rounds, 'balance' => 0, 'status' => 3, 'created_at' => $day,
    ]);

    if (! Wallet::find($competition->id)) {
        Wallet::forceCreate(['id' => $competition->id, 'customer_id' => Customer::factory()->create()->id, 'balance' => 0]);
    }

    WalletTransaction::create(['transaction_type' => $transactionType, 'sender_id' => $competition->id, 'receiver_id' => 1, 'initiator_id' => 1, 'amount' => $houseShare]);
}

it('reports daily income for the last 30 days by game kind', function () {
    singleGameOn('2026-10-16 09:00:00', 2, 2);
    singleGameOn('2026-10-16 10:00:00', 4, 4);
    singleGameOn('2026-10-16 11:00:00', 5, 50);
    singleGameOn('2026-10-16 11:30:00', 2, 70, payoutCustomerId: Customer::factory()->create()->id);
    singleGameOn('2026-10-10 09:00:00', 3, 3);
    singleGameOn('2026-09-16 09:00:00', 2, 7);
    singleGameOn('2026-09-15 23:00:00', 2, 99);

    competitionOn('2026-10-16 08:00:00', 1, 3, 10);
    competitionOn('2026-10-16 08:00:00', 1, 6, 80);
    competitionOn('2026-10-16 08:00:00', 1, 5, 300, transactionType: 'w2w');
    competitionOn('2026-10-16 08:00:00', 2, 21, 20);
    competitionOn('2026-10-10 08:00:00', 2, 13, 5);

    $data = $this->getJson('/api/v1/stats/income/daily-30-days', apiHeaders($this->apiKey->key))->assertOk()->json('data');

    expect($data['start_date'])->toBe('2026-09-17')
        ->and($data['end_date'])->toBe('2026-10-16')
        ->and($data['daily_stats'])->toHaveCount(30)
        ->and($data['daily_stats']['2026-10-16'])->toEqual(['single_games' => 6, 'tournaments' => 10, 'jackpots' => 20, 'total' => 36])
        ->and($data['daily_stats']['2026-10-10'])->toEqual(['single_games' => 3, 'tournaments' => 0, 'jackpots' => 5, 'total' => 8])
        ->and(array_sum(array_column($data['daily_stats'], 'total')))->toEqual(44);
});
