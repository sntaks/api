<?php

use App\Models\Customer;
use App\Models\PredictAuditLog;
use App\Models\PredictBet;
use App\Models\PredictCorrection;
use App\Models\PredictMarket;
use App\Models\PredictResolutionProposal;
use App\Models\Product;
use App\Models\Wallet;
use App\Services\Predict\CorrectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    setUpPredict($this);
    $this->fixedProduct = Product::factory()->create(['game_id' => $this->product->game_id, 'code' => 'predict_fixed']);
    Cache::flush();
    config(['predict.polymarket.enabled' => true, 'predict.polymarket.retries' => 1, 'predict.polymarket.retry_base_ms' => 0]);
    $this->gammaMarkets = [];
    Http::fake(['https://gamma-api.polymarket.com/markets/keyset*' => fn () => Http::response(['markets' => test()->gammaMarkets])]);
});

/**
 * Gamma's view of an imported market: resolved YES unless overridden.
 */
function gammaResolved(PredictMarket $market, array $overrides = []): array
{
    return array_merge([
        'id' => $market->external_market_id,
        'question' => $market->question,
        'conditionId' => '0xcond',
        'outcomes' => '["Yes", "No"]',
        'outcomePrices' => '["1", "0"]',
        'clobTokenIds' => '["tok-yes", "tok-no"]',
        'version' => 'v1',
        'closed' => true,
        'umaResolutionStatus' => 'resolved',
        'automaticallyResolved' => true,
    ], $overrides);
}

function balanceOfPlayer($player): float
{
    return (float) Wallet::where('customer_id', $player->id)->value('balance');
}

function admin(object $test, string $path, array $payload): TestResponse
{
    return $test->postJson('/api/v1/predict/admin/'.$path, $payload, $test->adminHeaders);
}

it('proposes Polymarket\'s result, closing the market, and pays out once an admin confirms', function () {
    $market = fixedMarket($this, '0.60', ['close_at' => now()->addMinutes(20)]);
    $player = predictPlayer(10000);
    quoteAndBet($this, $market, 'yes', 100, $player)->assertCreated();
    $this->travel(30)->minutes();
    $this->gammaMarkets = [gammaResolved($market)];

    $this->artisan('predict:sync-resolutions')->assertSuccessful();

    $proposal = PredictResolutionProposal::sole();
    expect($proposal->only(['result', 'status']))->toBe(['result' => 'yes', 'status' => 'pending'])
        ->and($market->fresh()->status)->toBe(PredictMarket::STATUS_CLOSED);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'closed=any') && str_contains($request->url(), 'id='.$market->external_market_id));

    admin($this, "proposals/{$proposal->id}/confirm", ['admin_user' => 'ops'])->assertOk()->assertJsonPath('data.proposal.status', 'confirmed');

    expect($market->fresh()->only(['status', 'result']))->toBe(['status' => 'settled', 'result' => 'yes'])
        ->and(balanceOfPlayer($player))->toBe(10058.0);
});

it('does not propose a result Polymarket has not settled', function (array $overrides) {
    $market = fixedMarket($this, '0.60', ['external_closed' => true]);
    $this->gammaMarkets = [gammaResolved($market, $overrides)];

    $this->artisan('predict:sync-resolutions')->assertSuccessful();

    expect(PredictResolutionProposal::count())->toBe(0);
})->with([
    'still proposed' => [['umaResolutionStatus' => 'proposed']],
    'prices not final' => [['outcomePrices' => '["0.97", "0.03"]']],
    'not closed' => [['closed' => false]],
]);

it('does not propose the same result again after an admin rejects it', function () {
    $market = fixedMarket($this, '0.60', ['external_closed' => true]);
    $this->gammaMarkets = [gammaResolved($market)];
    $this->artisan('predict:sync-resolutions')->assertSuccessful();

    admin($this, 'proposals/'.PredictResolutionProposal::sole()->id.'/reject', ['admin_user' => 'ops', 'reason' => 'Disputed on UMA'])->assertOk();
    $this->artisan('predict:sync-resolutions')->assertSuccessful();

    expect(PredictResolutionProposal::count())->toBe(1);
});

it('settles at once when auto-settle is on', function () {
    config(['predict.resolution.auto_settle' => true]);
    $market = fixedMarket($this, '0.60', ['external_closed' => true]);
    $this->gammaMarkets = [gammaResolved($market, ['outcomePrices' => '["0", "1"]'])];

    $this->artisan('predict:sync-resolutions')->assertSuccessful();

    expect($market->fresh()->only(['status', 'result']))->toBe(['status' => 'settled', 'result' => 'no'])
        ->and(PredictResolutionProposal::sole()->status)->toBe('confirmed');
});

/**
 * A pool (seed 1,000 at p 0.30, cut 20% of the losing side) settled YES: A 100 YES → 260, B 500 NO → 0,
 * C 200 YES → 520. With $terms the market can be put on the old whole-pool cut.
 *
 * @param  array<string, mixed>  $terms
 * @return array{0: PredictMarket, 1: Customer, 2: Customer, 3: Customer}
 */
function settledPool(object $test, array $terms = []): array
{
    $market = PredictMarket::factory()->open()->create(['product_id' => $test->product->id, 'seed_minor' => 100000, 'start_probability_bp' => 3000, 'cut_bp' => 2000, ...$terms]);
    [$a, $b, $c] = [predictPlayer(), predictPlayer(), predictPlayer()];
    placePredictBet($test, $a, $market->id, 'yes', 100)->assertCreated();
    placePredictBet($test, $b, $market->id, 'no', 500)->assertCreated();
    placePredictBet($test, $c, $market->id, 'yes', 200)->assertCreated();
    admin($test, "markets/{$market->id}/close", ['admin_user' => 'ops'])->assertOk();
    admin($test, "markets/{$market->id}/resolve", ['admin_user' => 'ops', 'result' => 'yes'])->assertOk();

    return [$market, $a, $b, $c];
}

it('corrects a wrong result once a second admin approves, moving every payout', function () {
    [$market, $a, $b, $c] = settledPool($this);

    $id = admin($this, "markets/{$market->id}/corrections", ['admin_user' => 'ops-1', 'result' => 'no', 'reason' => 'FKF reversed the result'])
        ->assertCreated()->json('data.correction.id');
    admin($this, "corrections/{$id}/approve", ['admin_user' => 'ops-2'])->assertOk();

    // NO at 1 + 600 × 0.8 / 1,200 = 1.40x: B is paid 700, A and C give back 260 and 520
    expect(balanceOfPlayer($a))->toBe(9900.0)
        ->and(balanceOfPlayer($b))->toBe(10200.0)
        ->and(balanceOfPlayer($c))->toBe(9800.0)
        ->and($market->fresh()->only(['status', 'result']))->toBe(['status' => 'settled', 'result' => 'no'])
        ->and(PredictCorrection::sole()->only(['status', 'wagers_corrected']))->toBe(['status' => 'done', 'wagers_corrected' => 3])
        ->and(PredictBet::where('customer_id', $b->id)->first()->only(['status', 'payout_minor', 'final_multiplier_x100']))->toBe(['status' => 'won', 'payout_minor' => 70000, 'final_multiplier_x100' => 140])
        ->and(PredictAuditLog::whereIn('action', ['request_correction', 'approve_correction', 'correct'])->count())->toBe(3);
});

it('corrects a market settled under the whole-pool cut by that rule', function () {
    [$market, , $b] = settledPool($this, ['cut_bp' => 1300, 'cut_on' => PredictMarket::CUT_ON_POOL]);

    $id = admin($this, "markets/{$market->id}/corrections", ['admin_user' => 'ops-1', 'result' => 'no', 'reason' => 'FKF reversed the result'])->json('data.correction.id');
    admin($this, "corrections/{$id}/approve", ['admin_user' => 'ops-2'])->assertOk();

    // NO at 1,800 × 0.87 / 1,200 = 1.30x: B is paid 650
    expect(balanceOfPlayer($b))->toBe(10150.0);
});

it('refuses an approval by the admin who asked for the correction', function () {
    [$market] = settledPool($this);
    $id = admin($this, "markets/{$market->id}/corrections", ['admin_user' => 'ops-1', 'result' => 'no', 'reason' => 'Wrong'])->json('data.correction.id');

    admin($this, "corrections/{$id}/approve", ['admin_user' => 'OPS-1'])->assertForbidden()->assertJsonPath('code', 'same_admin');

    expect(PredictCorrection::sole()->status)->toBe('requested')
        ->and($market->fresh()->result)->toBe('yes');
});

it('leaves a player who already withdrew a wrong payout with a negative balance', function () {
    [$market, , , $c] = settledPool($this);
    Wallet::where('customer_id', $c->id)->update(['balance' => 0]);
    $id = admin($this, "markets/{$market->id}/corrections", ['admin_user' => 'ops-1', 'result' => 'no', 'reason' => 'Wrong'])->json('data.correction.id');

    admin($this, "corrections/{$id}/approve", ['admin_user' => 'ops-2'])->assertOk();

    expect(balanceOfPlayer($c))->toBe(-520.0);
});

it('never moves a wager twice when a correction run repeats', function () {
    [$market, $a, $b] = settledPool($this);
    $id = admin($this, "markets/{$market->id}/corrections", ['admin_user' => 'ops-1', 'result' => 'no', 'reason' => 'Wrong'])->json('data.correction.id');
    admin($this, "corrections/{$id}/approve", ['admin_user' => 'ops-2'])->assertOk();
    $correction = PredictCorrection::sole();

    // As if the job crashed after moving the money and runs again
    $correction->update(['status' => PredictCorrection::STATUS_RUNNING]);
    app(CorrectionService::class)->run($correction);

    expect(balanceOfPlayer($a))->toBe(9900.0)
        ->and(balanceOfPlayer($b))->toBe(10200.0)
        ->and($correction->fresh()->status)->toBe('done');
});

it('only corrects settled markets, to a different result, one at a time', function () {
    [$market] = settledPool($this);
    $open = PredictMarket::factory()->open()->create(['product_id' => $this->product->id]);

    admin($this, "markets/{$open->id}/corrections", ['admin_user' => 'a', 'result' => 'no', 'reason' => 'x'])->assertConflict()->assertJsonPath('code', 'market_not_settled');
    admin($this, "markets/{$market->id}/corrections", ['admin_user' => 'a', 'result' => 'yes', 'reason' => 'x'])->assertConflict()->assertJsonPath('code', 'same_result');
    admin($this, "markets/{$market->id}/corrections", ['admin_user' => 'a', 'result' => 'no', 'reason' => 'x'])->assertCreated();
    admin($this, "markets/{$market->id}/corrections", ['admin_user' => 'a', 'result' => 'no', 'reason' => 'x'])->assertConflict()->assertJsonPath('code', 'correction_open');
});

it('lets a requested correction be cancelled', function () {
    [$market] = settledPool($this);
    $id = admin($this, "markets/{$market->id}/corrections", ['admin_user' => 'ops-1', 'result' => 'no', 'reason' => 'Wrong'])->json('data.correction.id');

    admin($this, "corrections/{$id}/cancel", ['admin_user' => 'ops-2', 'reason' => 'Result stands'])->assertOk()->assertJsonPath('data.correction.status', 'cancelled');
    admin($this, "corrections/{$id}/approve", ['admin_user' => 'ops-2'])->assertConflict()->assertJsonPath('code', 'correction_not_requested');
});
