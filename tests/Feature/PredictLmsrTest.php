<?php

use App\Models\PredictBet;
use App\Models\PredictLmsrState;
use App\Models\PredictMarket;
use App\Models\Product;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    setUpPredict($this);
    Product::factory()->create(['game_id' => $this->product->game_id, 'code' => 'predict_lmsr']);
    $this->player = predictPlayer(10000);
});

/** Create and publish an LMSR market through GMS. */
function lmsrMarket(object $test, float $probability = 0.5, array $overrides = []): PredictMarket
{
    $id = $test->postJson('/api/v1/predict/admin/markets', lmsrMarketPayload(['engine' => 'lmsr', 'start_probability' => $probability] + $overrides), $test->adminHeaders)
        ->assertCreated()->json('data.id');
    $test->postJson("/api/v1/predict/admin/markets/{$id}/publish", ['admin_user' => 'ops'], $test->adminHeaders)->assertOk();

    return PredictMarket::findOrFail($id);
}

function lmsrMarketPayload(array $overrides = []): array
{
    return array_merge([
        'admin_user' => 'ops',
        'question' => 'Will Kenya host AFCON 2027 matches in Nairobi?',
        'resolution_criteria' => 'YES if CAF confirms a match at Kasarani.',
        'category' => 'sport',
        'countries' => ['KE'],
        'close_at' => now()->addDays(10)->toIso8601String(),
        'resolution_deadline_at' => now()->addDays(12)->toIso8601String(),
        'start_probability' => 0.5,
    ], $overrides);
}

it('creates an LMSR market with the default liquidity and fee and shows its worst case', function () {
    $this->postJson('/api/v1/predict/admin/markets', lmsrMarketPayload(['engine' => 'lmsr']), $this->adminHeaders)
        ->assertCreated()
        ->assertJsonPath('data.engine', 'lmsr')
        ->assertJsonPath('data.product', 'predict_lmsr')
        ->assertJsonPath('data.liquidity', 5000)
        ->assertJsonPath('data.cut', 0.1)
        ->assertJsonPath('data.worst_case_loss', 3465.73);
});

it('opens at the admin\'s probability', function () {
    $market = lmsrMarket($this, 0.3);

    $this->getJson("/api/v1/predict/markets/{$market->id}", $this->siteHeaders)
        ->assertOk()
        ->assertJsonPath('data.betting_available', true)
        ->assertJsonPath('data.odds.yes_probability', 0.3)
        // (1 − 10% fee) / 0.30
        ->assertJsonPath('data.odds.yes_multiplier', 3);
});

it('quotes the shares a stake buys after the fee and locks them in the bet', function () {
    $market = lmsrMarket($this);

    // KES 1,000 − 10% buys 1,662.445594 shares at 50/50, b = 5,000
    quoteFor($this, $market, 'yes', 1000)->assertCreated()
        ->assertJsonPath('data.quote.potential_payout', 1662.44)
        ->assertJsonPath('data.quote.multiplier', 1.66);

    quoteAndBet($this, $market, 'yes', 1000)->assertCreated()
        ->assertJsonPath('data.bet.potential_payout', 1662.44)
        ->assertJsonPath('data.balance.balance', 9000);

    expect((string) PredictLmsrState::find($market->id)->q_yes)->toBe('1662.440000')
        ->and(PredictBet::sole()->shares_minor)->toBe(166244);
    $this->getJson("/api/v1/predict/markets/{$market->id}", $this->siteHeaders)->assertJsonPath('data.odds.yes_probability', 0.582365);
});

it('re-quotes when other bets made the quote more than 1% dearer for the house', function () {
    $market = lmsrMarket($this);
    $quoteId = quoteFor($this, $market, 'yes', 1000)->json('data.quote.quote_id');
    quoteAndBet($this, $market, 'yes', 3000, predictPlayer(10000))->assertCreated();

    betWithQuote($this, $market, $quoteId, 'yes', 1000)
        ->assertConflict()
        ->assertJsonPath('code', 'price_changed');
});

it('honours a quote when the price moved in the player\'s favour', function () {
    $market = lmsrMarket($this);
    $quoteId = quoteFor($this, $market, 'yes', 1000)->json('data.quote.quote_id');
    quoteAndBet($this, $market, 'no', 3000, predictPlayer(10000))->assertCreated();

    betWithQuote($this, $market, $quoteId, 'yes', 1000)->assertCreated()->assertJsonPath('data.bet.potential_payout', 1662.44);
});

it('pays winners their shares and keeps the losers\' stakes', function () {
    $market = lmsrMarket($this);
    $loser = predictPlayer(10000);
    quoteAndBet($this, $market, 'yes', 1000)->assertCreated();
    quoteAndBet($this, $market, 'no', 1000, $loser)->assertCreated();

    $this->postJson("/api/v1/predict/admin/markets/{$market->id}/close", ['admin_user' => 'ops'], $this->adminHeaders)->assertOk();
    $this->postJson("/api/v1/predict/admin/markets/{$market->id}/resolve", ['admin_user' => 'ops', 'result' => 'yes'], $this->adminHeaders)->assertOk();

    expect((float) Wallet::where('customer_id', $this->player->id)->value('balance'))->toBe(10662.44)
        ->and((float) Wallet::where('customer_id', $loser->id)->value('balance'))->toBe(9000.0)
        ->and(PredictBet::where('customer_id', $this->player->id)->value('status'))->toBe('won');
});

it('needs a quote to bet', function () {
    $market = lmsrMarket($this);

    placePredictBet($this, $this->player, $market->id, 'yes', 100)->assertUnprocessable()->assertJsonPath('code', 'quote_required');
});

it('charts the price after each bet', function () {
    $market = lmsrMarket($this);
    quoteAndBet($this, $market, 'yes', 1000)->assertCreated();

    $this->getJson("/api/v1/predict/markets/{$market->id}/history", $this->siteHeaders)
        ->assertOk()
        ->assertJsonCount(2, 'data.points')
        ->assertJsonPath('data.points.0.yes_probability', 0.5)
        ->assertJsonPath('data.points.1.yes_probability', 0.582365);
});

it('estimates an LMSR stake with the exact shares it would buy', function () {
    $market = lmsrMarket($this);

    $this->getJson("/api/v1/predict/markets/{$market->id}/estimate?side=yes&stake=1000", $this->siteHeaders)
        ->assertOk()
        ->assertJsonPath('data.estimated_payout', 1662.44);
});
