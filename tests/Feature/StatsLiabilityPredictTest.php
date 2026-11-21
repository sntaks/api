<?php

use App\Models\PredictMarket;
use App\Services\Predict\PoolMath;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    setUpPredict($this);
    config(['finance.test_customer_ids' => []]);
    $this->market = PredictMarket::factory()->open()->create(['product_id' => $this->product->id]);
});

it('takes the side that would cost the house most on a pool market', function () {
    placePredictBet($this, predictPlayer(), $this->market->id, 'yes', 100)->assertCreated();
    placePredictBet($this, predictPlayer(), $this->market->id, 'no', 300)->assertCreated();

    $market = $this->market->fresh();
    $pool = $market->pool;
    $total = $pool->yes_minor + $pool->no_minor;
    $ifYes = intdiv(10000 * PoolMath::multiplierX100($total, $pool->yes_minor, $market->cut_bp, $market->cutsFromWinnings()), 100);
    $ifNo = intdiv(30000 * PoolMath::multiplierX100($total, $pool->no_minor, $market->cut_bp, $market->cutsFromWinnings()), 100);

    $liability = $this->getJson('/api/v1/stats/liability?product=predict_pool', $this->adminHeaders)->assertOk()->json('data');

    expect($liability['by_product'][0])->toMatchArray([
        'product' => 'predict_pool', 'open_wagers' => 2, 'open_stakes' => 400,
        'open_potential_payout' => max($ifYes, $ifNo) / 100, 'potential_payout_basis' => 'worst_side',
    ])
        ->and(collect($liability['top_positions'])->pluck('side')->sort()->values()->all())->toBe(['no', 'yes']);
});
