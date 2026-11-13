<?php

use App\Models\LedgerEntry;
use App\Models\PredictBet;
use App\Models\PredictMarket;
use App\Models\PredictMarketBlock;
use App\Models\Wager;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    setUpPredict($this);
    // Seed KES 5,000 at 50/50, cut 10%: both sides open at 1.80x
    $this->market = PredictMarket::factory()->open()->create(['product_id' => $this->product->id]);
    $this->player = predictPlayer(1000);
});

it('debits the stake, records the bet and adds it to the pool', function () {
    $response = placePredictBet($this, $this->player, $this->market->id, 'yes', 100, 'kadi-bet-1');

    $response->assertCreated()
        ->assertJsonPath('data.bet.side', 'yes')
        ->assertJsonPath('data.bet.stake', 100)
        // (5,000 + 100) × 0.9 / (2,500 + 100)
        ->assertJsonPath('data.bet.multiplier_at_placement', 1.76)
        ->assertJsonPath('data.bet.is_estimate', true)
        ->assertJsonPath('data.balance.balance', 900)
        ->assertJsonPath('data.replayed', false);

    $wager = Wager::where('reference', 'kadi-bet-1')->firstOrFail();
    expect((float) Wallet::where('customer_id', $this->player->id)->value('balance'))->toBe(900.0)
        ->and($wager->product_id)->toBe($this->product->id)
        ->and($wager->round->reference)->toBe($this->market->round_reference)
        ->and(LedgerEntry::where('entry_type', 'wager_stake')->where('referenceable_type', Wager::class)->where('referenceable_id', $wager->id)->exists())->toBeTrue()
        ->and($this->market->pool->fresh()->yes_minor)->toBe(260000)
        ->and($this->market->pool->fresh()->bets_count)->toBe(1)
        ->and(PredictBet::where('wager_id', $wager->id)->value('stake_minor'))->toBe(10000);
});

it('replays a retry with the same reference without debiting twice', function () {
    placePredictBet($this, $this->player, $this->market->id, 'yes', 100, 'kadi-bet-1')->assertCreated();

    placePredictBet($this, $this->player, $this->market->id, 'yes', 100, 'kadi-bet-1')
        ->assertOk()
        ->assertJsonPath('data.replayed', true);

    expect((float) Wallet::where('customer_id', $this->player->id)->value('balance'))->toBe(900.0)
        ->and(PredictBet::count())->toBe(1)
        ->and($this->market->pool->fresh()->yes_minor)->toBe(260000);
});

it('refuses a different bet under a used reference', function () {
    placePredictBet($this, $this->player, $this->market->id, 'yes', 100, 'kadi-bet-1')->assertCreated();

    placePredictBet($this, $this->player, $this->market->id, 'no', 100, 'kadi-bet-1')
        ->assertConflict()
        ->assertJsonPath('code', 'reference_conflict');
});

it('leaves the pool untouched when the wallet cannot cover the stake', function () {
    $poor = predictPlayer(50);

    placePredictBet($this, $poor, $this->market->id, 'yes', 100)
        ->assertUnprocessable()
        ->assertJsonPath('code', 'insufficient_funds');

    expect($this->market->pool->fresh()->yes_minor)->toBe(250000)
        ->and(PredictBet::count())->toBe(0);
});

it('refuses stakes outside the market limits', function (float $stake, string $code) {
    placePredictBet($this, $this->player, $this->market->id, 'yes', $stake)
        ->assertUnprocessable()
        ->assertJsonPath('code', $code);
})->with([
    'below the KES 10 minimum' => [5, 'stake_below_limit'],
    'above the KES 5,000 maximum' => [5000.01, 'stake_above_limit'],
]);

it('refuses a player an admin blocked on the market', function () {
    PredictMarketBlock::create(['predict_market_id' => $this->market->id, 'customer_id' => $this->player->id, 'reason' => 'Team official', 'admin_user' => 'ops']);

    placePredictBet($this, $this->player, $this->market->id, 'yes', 100)
        ->assertForbidden()
        ->assertJsonPath('code', 'player_blocked');
});

it('refuses bets on a market that is not open', function (string $status) {
    $this->market->update(['status' => $status]);

    placePredictBet($this, $this->player, $this->market->id, 'yes', 100)
        ->assertConflict()
        ->assertJsonPath('code', 'market_not_open');
})->with([PredictMarket::STATUS_SUSPENDED, PredictMarket::STATUS_CLOSED]);

it('refuses bets after the close time even before the market is closed', function () {
    $this->market->update(['close_at' => now()->subMinute()]);

    placePredictBet($this, $this->player, $this->market->id, 'yes', 100)
        ->assertConflict()
        ->assertJsonPath('code', 'market_not_open');
});

it('does not take bets on a draft', function () {
    $draft = PredictMarket::factory()->create(['product_id' => $this->product->id]);

    placePredictBet($this, $this->player, $draft->id, 'yes', 100)->assertNotFound();
});

it('refuses a stake that would push its side below the minimum multiplier and says what still fits', function () {
    // Seed KES 100 at 80% YES, cut 20% of the losing side, minimum 1.05x: YES opens at 1.20x and stays at or
    // above 1.05x up to 320 on the side, so at most 240 more fits
    $market = PredictMarket::factory()->open()->create([
        'product_id' => $this->product->id, 'seed_minor' => 10000, 'start_probability_bp' => 8000, 'min_multiplier_x100' => 105,
    ]);

    $response = placePredictBet($this, $this->player, $market->id, 'yes', 300);

    $response->assertUnprocessable()->assertJsonPath('code', 'pool_side_full');
    $maxStake = $response->json('max_stake');
    expect($maxStake)->toBe(240);

    placePredictBet($this, $this->player, $market->id, 'yes', $maxStake)->assertCreated();
});

it('keeps the pool equal to the seed plus every recorded bet over many bets', function () {
    $players = collect(range(1, 5))->map(fn () => predictPlayer(1000));

    foreach ($players as $index => $player) {
        placePredictBet($this, $player, $this->market->id, $index % 2 ? 'no' : 'yes', 10 * ($index + 1))->assertCreated();
    }

    $pool = $this->market->pool->fresh();
    expect($pool->yes_minor - $pool->seed_yes_minor)->toBe((int) PredictBet::where('side', 'yes')->sum('stake_minor'))
        ->and($pool->no_minor - $pool->seed_no_minor)->toBe((int) PredictBet::where('side', 'no')->sum('stake_minor'));
});

it('lists the player\'s bets with the current estimate', function () {
    placePredictBet($this, $this->player, $this->market->id, 'no', 100)->assertCreated();

    $this->getJson('/api/v1/customers/'.encryptId($this->player->id).'/predict/bets', $this->siteHeaders)
        ->assertOk()
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.side', 'no')
        ->assertJsonPath('data.items.0.status', 'open')
        ->assertJsonPath('data.items.0.multiplier', 1.76)
        ->assertJsonPath('data.items.0.potential_payout', 176);
});

it('refuses a game key on the player bet route', function () {
    $this->postJson('/api/v1/customers/'.encryptId($this->player->id).'/predict/bets', [], apiHeaders('game_predict_key'))
        ->assertForbidden()
        ->assertJsonPath('code', 'route_not_allowed');
});

it('validates the bet payload', function () {
    $this->postJson('/api/v1/customers/'.encryptId($this->player->id).'/predict/bets', [], $this->siteHeaders)
        ->assertUnprocessable()
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonValidationErrors(['market_id', 'side', 'stake', 'reference']);
});
