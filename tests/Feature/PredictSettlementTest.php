<?php

use App\Models\Customer;
use App\Models\PredictBet;
use App\Models\PredictMarket;
use App\Models\PredictSettlement;
use App\Models\Wager;
use App\Models\Wallet;
use App\Models\WinningsTaxCharge;
use App\Services\Predict\SettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    setUpPredict($this);
    // The brief's pool: seed KES 1,000 at p = 0.30, cut 13%
    $this->market = PredictMarket::factory()->open()->create([
        'product_id' => $this->product->id, 'seed_minor' => 100000, 'start_probability_bp' => 3000, 'cut_bp' => 1300,
    ]);
});

function balanceOf(Customer $player): float
{
    return (float) Wallet::where('customer_id', $player->id)->value('balance');
}

function adminAction(object $test, PredictMarket $market, string $action, array $payload = []): TestResponse
{
    return $test->postJson("/api/v1/predict/admin/markets/{$market->id}/{$action}", $payload + ['admin_user' => 'ops@kadi'], $test->adminHeaders);
}

it('pays the brief\'s worked example when YES wins and books +17 for the house', function () {
    [$a, $b, $c] = [predictPlayer(), predictPlayer(), predictPlayer()];
    placePredictBet($this, $a, $this->market->id, 'yes', 100)->assertCreated();
    placePredictBet($this, $b, $this->market->id, 'no', 500)->assertCreated();
    placePredictBet($this, $c, $this->market->id, 'yes', 200)->assertCreated();

    adminAction($this, $this->market, 'close')->assertOk();
    adminAction($this, $this->market, 'resolve', ['result' => 'yes'])->assertOk();

    $run = PredictSettlement::where('predict_market_id', $this->market->id)->firstOrFail();
    expect(balanceOf($a))->toBe(10161.0)
        ->and(balanceOf($c))->toBe(10322.0)
        ->and(balanceOf($b))->toBe(9500.0)
        ->and((float) $this->house->fresh()->balance)->toBe(100017.0)
        ->and($run->status)->toBe(PredictSettlement::STATUS_DONE)
        ->and($run->final_multiplier_x100)->toBe(261)
        ->and($run->house_net_minor)->toBe(1700)
        ->and($this->market->fresh()->status)->toBe(PredictMarket::STATUS_SETTLED)
        ->and(PredictBet::where('customer_id', $a->id)->first()->only(['status', 'payout_minor']))->toBe(['status' => 'won', 'payout_minor' => 26100])
        ->and(PredictBet::where('customer_id', $b->id)->first()->only(['status', 'payout_minor']))->toBe(['status' => 'lost', 'payout_minor' => 0]);
});

it('loses at most the seed: 500 on YES against the opening pool costs the house 315', function () {
    $player = predictPlayer();
    placePredictBet($this, $player, $this->market->id, 'yes', 500)->assertCreated();

    adminAction($this, $this->market, 'close')->assertOk();
    adminAction($this, $this->market, 'resolve', ['result' => 'yes'])->assertOk();

    expect(balanceOf($player))->toBe(10315.0)
        ->and(PredictSettlement::where('predict_market_id', $this->market->id)->value('house_net_minor'))->toBe(-31500);
});

it('withholds winnings tax on the profit at the product rate', function () {
    $this->product->update(['winnings_tax_rate' => '0.2000']);
    $player = predictPlayer();
    placePredictBet($this, $player, $this->market->id, 'yes', 100)->assertCreated();

    adminAction($this, $this->market, 'close')->assertOk();
    adminAction($this, $this->market, 'resolve', ['result' => 'yes'])->assertOk();

    // Opening pool + 100 YES: 1,100 × 0.87 / 400 = 2.39x, so 239 paid, 20% of the 139 profit withheld
    expect(balanceOf($player))->toBe(10000 - 100 + 239 - 27.8)
        ->and((float) WinningsTaxCharge::sum('amount'))->toBe(27.8);
});

it('never pays twice when a settlement run is repeated', function () {
    $player = predictPlayer();
    placePredictBet($this, $player, $this->market->id, 'yes', 100)->assertCreated();
    adminAction($this, $this->market, 'close')->assertOk();
    adminAction($this, $this->market, 'resolve', ['result' => 'yes'])->assertOk();
    $run = PredictSettlement::firstOrFail();
    $paidBalance = balanceOf($player);

    // As if the job crashed after paying and is retried
    $run->update(['status' => PredictSettlement::STATUS_FAILED]);
    $this->postJson("/api/v1/predict/admin/settlements/{$run->id}/retry", ['admin_user' => 'ops@kadi'], $this->adminHeaders)->assertOk();

    expect(balanceOf($player))->toBe($paidBalance)
        ->and($run->fresh()->status)->toBe(PredictSettlement::STATUS_DONE)
        ->and($run->fresh()->attempts)->toBe(2);
});

it('marks the run failed instead of throwing when the round refuses', function () {
    $player = predictPlayer();
    placePredictBet($this, $player, $this->market->id, 'yes', 100)->assertCreated();
    $this->predictKey->update(['is_active' => false]);

    adminAction($this, $this->market, 'close')->assertOk();
    adminAction($this, $this->market, 'resolve', ['result' => 'yes'])->assertOk();

    $run = PredictSettlement::firstOrFail();
    expect($run->status)->toBe(PredictSettlement::STATUS_FAILED)
        ->and($run->error)->toContain('game API key')
        ->and($this->market->fresh()->status)->toBe(PredictMarket::STATUS_RESOLVED)
        ->and(balanceOf($player))->toBe(9900.0);
});

it('refunds every stake when a market is voided', function () {
    [$a, $b] = [predictPlayer(), predictPlayer()];
    placePredictBet($this, $a, $this->market->id, 'yes', 100)->assertCreated();
    placePredictBet($this, $b, $this->market->id, 'no', 250)->assertCreated();

    adminAction($this, $this->market, 'void', ['reason' => 'Event cancelled'])->assertOk();

    expect(balanceOf($a))->toBe(10000.0)
        ->and(balanceOf($b))->toBe(10000.0)
        ->and($this->market->fresh()->status)->toBe(PredictMarket::STATUS_VOIDED)
        ->and(PredictBet::pluck('status')->unique()->all())->toBe(['void'])
        ->and(PredictSettlement::firstOrFail()->only(['kind', 'status']))->toBe(['kind' => 'void', 'status' => 'done']);
});

it('does nothing when a finished run is run again', function () {
    $player = predictPlayer();
    placePredictBet($this, $player, $this->market->id, 'no', 100)->assertCreated();
    adminAction($this, $this->market, 'close')->assertOk();
    adminAction($this, $this->market, 'resolve', ['result' => 'no'])->assertOk();
    $run = PredictSettlement::firstOrFail();

    app(SettlementService::class)->run($run);

    expect($run->fresh()->attempts)->toBe(1);
});

it('settles the rest of the market when GMS voided one of its wagers on its own', function () {
    [$a, $b] = [predictPlayer(), predictPlayer()];
    placePredictBet($this, $a, $this->market->id, 'yes', 100)->assertCreated();
    placePredictBet($this, $b, $this->market->id, 'yes', 100, 'voided-by-gms')->assertCreated();
    $wagerId = Wager::where('reference', 'voided-by-gms')->value('id');
    $this->postJson("/api/v1/wagers/{$wagerId}/void", ['reason' => 'Late bet', 'admin_user' => 'ops'], $this->adminHeaders)->assertOk();

    adminAction($this, $this->market, 'close')->assertOk();
    adminAction($this, $this->market, 'resolve', ['result' => 'yes'])->assertOk();

    expect(PredictSettlement::firstOrFail()->status)->toBe(PredictSettlement::STATUS_DONE)
        ->and(balanceOf($b))->toBe(10000.0)
        ->and(PredictBet::where('customer_id', $b->id)->value('status'))->toBe('void')
        ->and(PredictBet::where('customer_id', $a->id)->value('status'))->toBe('won');
});

it('lets a run stuck in running be retried once it is stale, not before', function () {
    $player = predictPlayer();
    placePredictBet($this, $player, $this->market->id, 'yes', 100)->assertCreated();
    adminAction($this, $this->market, 'close')->assertOk();
    adminAction($this, $this->market, 'resolve', ['result' => 'yes'])->assertOk();
    $run = PredictSettlement::firstOrFail();
    $run->update(['status' => PredictSettlement::STATUS_RUNNING]);
    $retry = fn () => $this->postJson("/api/v1/predict/admin/settlements/{$run->id}/retry", ['admin_user' => 'ops'], $this->adminHeaders);

    $retry()->assertConflict()->assertJsonPath('code', 'settlement_not_retryable');

    $this->travel(16)->minutes();
    $retry()->assertOk();

    expect($run->fresh()->status)->toBe(PredictSettlement::STATUS_DONE);
});
