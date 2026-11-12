<?php

use App\Models\Customer;
use App\Models\PredictBet;
use App\Models\PredictMarket;
use App\Models\RiskEvent;
use App\Models\Wager;
use App\Models\Wallet;
use App\Services\Predict\RiskService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    setUpPredict($this);
    $this->market = PredictMarket::factory()->open()->create(['product_id' => $this->product->id, 'seed_minor' => 10000000]);
});

/** An established customer (registered a month ago) with a funded wallet. */
function veteran(float $balance = 10000): Customer
{
    $player = predictPlayer($balance);
    $player->forceFill(['created_at' => now()->subMonth()])->save();

    return $player;
}

function riskTypes(): array
{
    return RiskEvent::orderBy('id')->pluck('event_type')->all();
}

it('flags a large bet close to the betting cut-off, and lets the bet stand', function () {
    $this->market->update(['close_at' => now()->addMinutes(20)]);

    placePredictBet($this, veteran(), $this->market->id, 'yes', 2000)->assertCreated();

    expect(riskTypes())->toBe([RiskService::TYPE_LATE_LARGE])
        ->and(RiskEvent::sole()->details['market_id'])->toBe($this->market->id);
});

it('does not flag small or early bets', function () {
    placePredictBet($this, veteran(), $this->market->id, 'yes', 2000)->assertCreated();
    $this->market->update(['close_at' => now()->addMinutes(20)]);
    placePredictBet($this, veteran(), $this->market->id, 'yes', 1999)->assertCreated();

    expect(RiskEvent::count())->toBe(0);
});

it('flags a fresh account once it has staked enough on one market', function () {
    $fresh = predictPlayer(10000);
    placePredictBet($this, $fresh, $this->market->id, 'yes', 1500)->assertCreated();
    expect(RiskEvent::count())->toBe(0);

    placePredictBet($this, $fresh, $this->market->id, 'yes', 500)->assertCreated();
    placePredictBet($this, $fresh, $this->market->id, 'yes', 500)->assertCreated();

    expect(riskTypes())->toBe([RiskService::TYPE_FRESH_ACCOUNT]);
});

it('flags five customers backing one side within ten minutes, once', function () {
    foreach (range(1, 6) as $i) {
        placePredictBet($this, veteran(), $this->market->id, 'no', 1000)->assertCreated();
    }

    expect(riskTypes())->toBe([RiskService::TYPE_CLUSTER])
        ->and(RiskEvent::sole()->details['side'])->toBe('no')
        ->and(RiskEvent::sole()->details['customer_ids'])->toHaveCount(5);
});

it('flags a customer who wins far more often than chance', function () {
    $sharp = veteran();
    $other = PredictMarket::factory()->open()->create(['product_id' => $this->product->id]);
    foreach (range(1, 10) as $i) {
        PredictBet::create([
            'predict_market_id' => $other->id, 'customer_id' => $sharp->id, 'reference' => "old-{$i}", 'side' => 'yes', 'currency' => 'KES',
            'wager_id' => Wager::factory()->create([
                'customer_id' => $sharp->id, 'wallet_id' => Wallet::where('customer_id', $sharp->id)->value('id'),
                'product_id' => $this->product->id, 'api_key_id' => $this->predictKey->id,
            ])->id,
            'stake_minor' => 1000, 'estimated_multiplier_x100' => 200, 'status' => $i <= 8 ? 'won' : 'lost', 'placed_at' => now()->subWeek(),
        ]);
    }

    placePredictBet($this, $sharp, $this->market->id, 'yes', 10)->assertCreated();
    placePredictBet($this, $sharp, $this->market->id, 'yes', 10)->assertCreated();

    expect(riskTypes())->toBe([RiskService::TYPE_SHARP_WINNER])
        ->and(RiskEvent::sole()->details['win_rate'])->toBe(0.8);
});

it('lists open prediction flags for GMS and resolves one with a reason', function () {
    $this->market->update(['close_at' => now()->addMinutes(20)]);
    placePredictBet($this, veteran(), $this->market->id, 'yes', 2000)->assertCreated();
    $flag = RiskEvent::sole();

    $this->getJson("/api/v1/predict/admin/risk-events?market_id={$this->market->id}", $this->adminHeaders)
        ->assertOk()
        ->assertJsonPath('data.items.0.type', RiskService::TYPE_LATE_LARGE);

    $this->postJson("/api/v1/predict/admin/risk-events/{$flag->id}/resolve", ['admin_user' => 'ops', 'reason' => 'Regular high roller'], $this->adminHeaders)
        ->assertOk()
        ->assertJsonPath('data.risk_event.details.resolution', 'Regular high roller');

    $this->getJson('/api/v1/predict/admin/risk-events', $this->adminHeaders)->assertOk()->assertJsonCount(0, 'data.items');
});
