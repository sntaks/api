<?php

use App\Models\PredictMarket;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    setUpPredict($this);
});

it('lists open markets for players, never drafts', function () {
    $open = PredictMarket::factory()->open()->create(['product_id' => $this->product->id]);
    PredictMarket::factory()->create(['product_id' => $this->product->id]);

    $this->getJson('/api/v1/predict/markets', $this->siteHeaders)
        ->assertOk()
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.id', $open->id)
        ->assertJsonPath('data.items.0.odds.yes_probability', 0.5)
        ->assertJsonPath('data.items.0.odds.yes_multiplier', 1.8)
        ->assertJsonPath('data.items.0.timezone', 'Africa/Nairobi');
});

it('filters the list by category', function () {
    PredictMarket::factory()->open()->create(['product_id' => $this->product->id, 'category' => 'sport']);
    $politics = PredictMarket::factory()->open()->create(['product_id' => $this->product->id, 'category' => 'politics']);

    $this->getJson('/api/v1/predict/markets?category=politics', $this->siteHeaders)
        ->assertOk()
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.id', $politics->id);
});

it('sorts closing soon first by default', function () {
    $later = PredictMarket::factory()->open()->create(['product_id' => $this->product->id, 'close_at' => now()->addDays(5)]);
    $sooner = PredictMarket::factory()->open()->create(['product_id' => $this->product->id, 'close_at' => now()->addDay()]);

    expect($this->getJson('/api/v1/predict/markets', $this->siteHeaders)->json('data.items.*.id'))->toBe([$sooner->id, $later->id]);
});

it('hides a draft\'s details', function () {
    $draft = PredictMarket::factory()->create(['product_id' => $this->product->id]);

    $this->getJson("/api/v1/predict/markets/{$draft->id}", $this->siteHeaders)->assertNotFound();
});

it('estimates the multiplier and payout for a stake', function () {
    $market = PredictMarket::factory()->open()->create(['product_id' => $this->product->id]);

    $this->getJson("/api/v1/predict/markets/{$market->id}/estimate?side=yes&stake=100", $this->siteHeaders)
        ->assertOk()
        ->assertJsonPath('data.estimated_multiplier', 1.76)
        ->assertJsonPath('data.estimated_payout', 176)
        ->assertJsonPath('data.is_estimate', true);
});

it('charts the YES share after the seed and each bet', function () {
    $market = PredictMarket::factory()->open()->create(['product_id' => $this->product->id]);
    placePredictBet($this, predictPlayer(), $market->id, 'yes', 5000)->assertCreated();

    $this->getJson("/api/v1/predict/markets/{$market->id}/history", $this->siteHeaders)
        ->assertOk()
        ->assertJsonCount(2, 'data.points')
        ->assertJsonPath('data.points.0.yes_probability', 0.5)
        // 7,500 of 10,000
        ->assertJsonPath('data.points.1.yes_probability', 0.75);
});

it('feeds pollers only the markets that changed', function () {
    $quiet = PredictMarket::factory()->open()->create(['product_id' => $this->product->id]);
    $busy = PredictMarket::factory()->open()->create(['product_id' => $this->product->id]);
    $this->travel(1)->minutes();
    $since = now()->toIso8601String();
    $this->travel(1)->seconds();
    placePredictBet($this, predictPlayer(), $busy->id, 'no', 100)->assertCreated();

    $this->getJson('/api/v1/predict/updates?since='.urlencode($since), $this->siteHeaders)
        ->assertOk()
        ->assertJsonCount(1, 'data.markets')
        ->assertJsonPath('data.markets.0.id', $busy->id);
});

it('refuses an estimate on a market that is not open', function () {
    $market = PredictMarket::factory()->open()->create(['product_id' => $this->product->id, 'status' => PredictMarket::STATUS_CLOSED]);

    $this->getJson("/api/v1/predict/markets/{$market->id}/estimate?side=yes&stake=100", $this->siteHeaders)
        ->assertConflict()
        ->assertJsonPath('code', 'market_not_open');
});
