<?php

use App\Models\PredictBet;
use App\Models\PredictExposure;
use App\Models\PredictOutcome;
use App\Models\PredictQuote;
use App\Models\Product;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    setUpPredict($this);
    $this->fixedProduct = Product::factory()->create(['game_id' => $this->product->game_id, 'code' => 'predict_fixed']);
    $this->player = predictPlayer(10000);
});

it('quotes the fair price plus the margin for ten seconds', function () {
    $market = fixedMarket($this, '0.60');

    quoteFor($this, $market)
        ->assertCreated()
        ->assertJsonPath('data.quote.multiplier', 1.58)
        ->assertJsonPath('data.quote.potential_payout', 158)
        ->assertJsonPath('data.quote.price', 0.63)
        ->assertJsonPath('data.quote.expires_at', now()->addSeconds(10)->toIso8601String());
});

it('places a bet at the quoted multiplier and books its exposure', function () {
    $market = fixedMarket($this, '0.60');

    quoteAndBet($this, $market)
        ->assertCreated()
        ->assertJsonPath('data.bet.multiplier_at_placement', 1.58)
        ->assertJsonPath('data.balance.balance', 9900);

    $yes = PredictExposure::forMarket($market->id)->where('side', 'yes')->sole();
    expect([$yes->stakes_minor, $yes->payouts_minor])->toBe([10000, 15800])
        ->and(PredictQuote::sole()->used_at)->not->toBeNull()
        ->and(PredictBet::sole()->estimated_multiplier_x100)->toBe(158);
});

it('refuses a quote used, expired or for another stake', function () {
    $market = fixedMarket($this);
    $quoteId = quoteFor($this, $market)->json('data.quote.quote_id');
    betWithQuote($this, $market, $quoteId)->assertCreated();

    betWithQuote($this, $market, $quoteId)->assertConflict()->assertJsonPath('code', 'quote_used');

    $other = quoteFor($this, $market)->json('data.quote.quote_id');
    betWithQuote($this, $market, $other, stake: 200)->assertConflict()->assertJsonPath('code', 'quote_mismatch');

    $this->travel(11)->seconds();
    setPrice($market, 'yes', '0.60');
    betWithQuote($this, $market, $other)->assertConflict()->assertJsonPath('code', 'quote_expired');
});

it('re-quotes when the price moved more than 1% against the house', function () {
    $market = fixedMarket($this, '0.60');
    $quoteId = quoteFor($this, $market)->json('data.quote.quote_id');
    setPrice($market, 'yes', '0.62');

    betWithQuote($this, $market, $quoteId)
        ->assertConflict()
        ->assertJsonPath('code', 'price_changed')
        ->assertJsonPath('quote.multiplier', 1.53);

    expect(PredictBet::count())->toBe(0);
});

it('keeps the quoted multiplier when the price moved within tolerance or in the player\'s favour', function (string $newMid) {
    $market = fixedMarket($this, '0.60');
    $quoteId = quoteFor($this, $market)->json('data.quote.quote_id');
    setPrice($market, 'yes', $newMid);

    betWithQuote($this, $market, $quoteId)->assertCreated()->assertJsonPath('data.bet.multiplier_at_placement', 1.58);
})->with(['within 1%' => '0.605', 'in the player\'s favour' => '0.50']);

it('refuses to quote when a betting guard fails', function (callable $break, string $reason) {
    $market = fixedMarket($this);
    $break($market);

    quoteFor($this, $market)->assertConflict()->assertJsonPath('code', 'price_unavailable')->assertJsonPath('reason', $reason);
})->with([
    'price older than 45 s' => [fn ($m) => PredictOutcome::where('predict_market_id', $m->id)->update(['price_captured_at' => now()->subSeconds(46)]), 'stale_price'],
    'spread above 0.05' => [fn ($m) => setPrice($m, 'yes', '0.60', spread: '0.06'), 'wide_spread'],
    'book under USD 1,000' => [fn ($m) => setPrice($m, 'yes', '0.60', depth: '999'), 'thin_book'],
]);

it('stops a side at the house\'s exposure cap', function () {
    // 1.58x on 5,000 → payout 7,900, liability 2,900; cap it at 2,000
    $market = fixedMarket($this, '0.60', ['max_liability_minor' => 200000]);

    quoteAndBet($this, $market, stake: 5000)->assertConflict()->assertJsonPath('code', 'exposure_limit');
    quoteAndBet($this, $market, stake: 1000)->assertCreated();
});

it('caps what one customer may stake on a market', function () {
    $market = fixedMarket($this, '0.60', ['max_customer_stake_minor' => 20000]);
    quoteAndBet($this, $market, stake: 150)->assertCreated();

    quoteAndBet($this, $market, stake: 100)->assertUnprocessable()->assertJsonPath('code', 'customer_limit')->assertJsonPath('remaining', 50);
});

it('stops betting at the game start, or 15 minutes before close without one', function (array $overrides) {
    $market = fixedMarket($this, '0.60', $overrides);

    quoteFor($this, $market)->assertConflict()->assertJsonPath('code', 'betting_closed');
    $this->getJson("/api/v1/predict/markets/{$market->id}/estimate?side=yes&stake=100", $this->siteHeaders)
        ->assertConflict()
        ->assertJsonPath('code', 'betting_closed');
})->with([
    // Closures: a dataset's now() would run before the app sets its timezone
    'game started' => [fn () => ['external_game_start_at' => now()->subMinute(), 'close_at' => now()->addHours(3)]],
    'no game start, 10 min to close' => [fn () => ['close_at' => now()->addMinutes(10)]],
]);

it('pays each winner at the multiplier it was placed at', function () {
    $market = fixedMarket($this, '0.60');
    $loser = predictPlayer(10000);
    quoteAndBet($this, $market, 'yes', 100)->assertCreated();
    quoteAndBet($this, $market, 'no', 100, $loser)->assertCreated();

    $this->postJson("/api/v1/predict/admin/markets/{$market->id}/close", ['admin_user' => 'ops'], $this->adminHeaders)->assertOk();
    $this->postJson("/api/v1/predict/admin/markets/{$market->id}/resolve", ['admin_user' => 'ops', 'result' => 'yes'], $this->adminHeaders)->assertOk();

    expect((float) Wallet::where('customer_id', $this->player->id)->value('balance'))->toBe(10058.0)
        ->and((float) Wallet::where('customer_id', $loser->id)->value('balance'))->toBe(9900.0)
        ->and(PredictBet::where('customer_id', $this->player->id)->first()->only(['status', 'payout_minor', 'final_multiplier_x100']))
        ->toBe(['status' => 'won', 'payout_minor' => 15800, 'final_multiplier_x100' => 158]);
});

it('shows GMS the exposure per side', function () {
    $market = fixedMarket($this, '0.60');
    quoteAndBet($this, $market, 'yes', 100)->assertCreated();

    $this->getJson("/api/v1/predict/admin/markets/{$market->id}", $this->adminHeaders)
        ->assertOk()
        ->assertJsonPath('data.exposure.yes.payouts_if_wins', 158)
        ->assertJsonPath('data.exposure.yes.house_result_if_wins', -58)
        ->assertJsonPath('data.exposure.worst_case', -58);
});

it('quotes a wider margin once a side\'s liability passes half its cap', function () {
    // Cap KES 1,000: yes owes KES 900 against KES 300 staked, so liability 600 = 60% → margin 5% + 2%
    $market = fixedMarket($this, '0.60', ['max_liability_minor' => 100000]);
    PredictExposure::forMarket($market->id)->where('side', 'yes')->update(['stakes_minor' => 30000, 'payouts_minor' => 90000]);

    // 0.60 × 1.07 = 0.642 → 1.55x (instead of 1.58x)
    quoteFor($this, $market, stake: 10)->assertCreated()->assertJsonPath('data.quote.multiplier', 1.55);
    quoteFor($this, $market, 'no', 10)->assertCreated()->assertJsonPath('data.quote.multiplier', 2.38);
});

it('shows an open fixed-odds bet with its locked multiplier and payout', function () {
    $market = fixedMarket($this, '0.60');
    quoteAndBet($this, $market, 'yes', 100)->assertCreated();

    $this->getJson('/api/v1/customers/'.encryptId($this->player->id).'/predict/bets', $this->siteHeaders)
        ->assertOk()
        ->assertJsonPath('data.items.0.multiplier', 1.58)
        ->assertJsonPath('data.items.0.potential_payout', 158)
        ->assertJsonPath('data.items.0.is_estimate', false);
});
