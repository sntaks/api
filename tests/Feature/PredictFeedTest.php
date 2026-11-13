<?php

use App\Models\Customer;
use App\Models\PredictBet;
use App\Models\PredictEvent;
use App\Models\PredictMarket;
use App\Models\PredictOutcome;
use App\Models\Product;
use App\Services\Predict\Polymarket\BookSummary;
use App\Services\Predict\Polymarket\ReferencePriceRecorder;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function () {
    setUpPredict($this);
    $this->fixedProduct = Product::factory()->create(['game_id' => $this->product->game_id, 'code' => 'predict_fixed']);
    Product::factory()->create(['game_id' => $this->product->game_id, 'code' => 'predict_lmsr']);
    $this->player = predictPlayer(10000);
});

/** The ids the polling feed returns for changes after $since. */
function changedMarketIds(object $test, string $since, ?int $afterId = null): array
{
    $query = http_build_query(array_filter(['since' => $since, 'after_id' => $afterId]));

    return $test->getJson("/api/v1/predict/updates?{$query}", $test->siteHeaders)->assertOk()->json('data.markets.*.id');
}

/** Create and publish an LMSR market through GMS. */
function lmsrFeedMarket(object $test): PredictMarket
{
    $id = $test->postJson('/api/v1/predict/admin/markets', [
        'admin_user' => 'ops',
        'engine' => 'lmsr',
        'question' => 'Will Kenya host AFCON 2027 matches in Nairobi?',
        'resolution_criteria' => 'YES if CAF confirms a match at Kasarani.',
        'category' => 'sport',
        'countries' => ['KE'],
        'close_at' => now()->addDays(10)->toIso8601String(),
        'resolution_deadline_at' => now()->addDays(12)->toIso8601String(),
        'start_probability' => 0.5,
    ], $test->adminHeaders)->assertCreated()->json('data.id');
    $test->postJson("/api/v1/predict/admin/markets/{$id}/publish", ['admin_user' => 'ops'], $test->adminHeaders)->assertOk();

    return PredictMarket::findOrFail($id);
}

/** A book at $mid that passes the betting guards. */
function bookAt(string $mid): BookSummary
{
    return new BookSummary(midpoint: $mid, bestBid: bcsub($mid, '0.01', 6), bestAsk: bcadd($mid, '0.01', 6), spread: '0.02', depthUsd: '50000');
}

it('feeds pollers an LMSR market after a bet moved its price', function () {
    $quiet = lmsrFeedMarket($this);
    $busy = lmsrFeedMarket($this);
    $this->travel(1)->minutes();
    $since = now()->toIso8601String();
    $this->travel(5)->seconds();
    quoteAndBet($this, $busy, 'yes', 1000)->assertCreated();

    expect(changedMarketIds($this, $since))->toBe([$busy->id]);
});

it('feeds pollers an imported market after a bet changed its exposure', function () {
    $quiet = fixedMarket($this);
    $busy = fixedMarket($this);
    $this->travel(1)->minutes();
    $since = now()->toIso8601String();
    $this->travel(5)->seconds();
    // Both books are captured again, unchanged, so only the bet tells the two markets apart
    foreach ([$quiet, $busy] as $market) {
        setPrice($market, 'yes', '0.60');
        setPrice($market, 'no', '0.40');
    }
    quoteAndBet($this, $busy, 'yes', 100)->assertCreated();

    expect(changedMarketIds($this, $since))->toBe([$busy->id]);
});

it('leaves an imported market out of the feed while its book is unchanged, and feeds it when the price moves', function () {
    $still = fixedMarket($this, '0.60');
    $moving = fixedMarket($this, '0.60');
    $recorder = app(ReferencePriceRecorder::class);
    $tokens = PredictOutcome::pluck('external_token_id')->all();
    $this->travel(20)->seconds();
    $since = now()->toIso8601String();
    $this->travel(5)->seconds();

    $recorder->recordStream(["tok-{$moving->id}-yes" => bookAt('0.65')], $tokens);

    $answer = $this->getJson('/api/v1/predict/updates?since='.urlencode($since), $this->siteHeaders)->assertOk();

    expect($answer->json('data.markets.*.id'))->toBe([$moving->id])
        ->and($answer->json('data.reference_prices_at'))->toBe(now()->toIso8601String())
        ->and(PredictOutcome::where('external_token_id', "tok-{$still->id}-yes")->value('price_captured_at')->toIso8601String())->toBe(now()->toIso8601String());
});

it('feeds an imported market whose price was too old to bet on and is fresh again', function () {
    $market = fixedMarket($this, '0.60');
    $tokens = PredictOutcome::pluck('external_token_id')->all();
    $this->travel(2)->minutes();
    $since = now()->toIso8601String();
    $this->travel(5)->seconds();

    app(ReferencePriceRecorder::class)->recordStream([], $tokens);

    expect(changedMarketIds($this, $since))->toBe([$market->id]);
});

it('returns 500 markets and says when there are more, read on with after_id', function () {
    $markets = PredictMarket::factory()->open()->count(500)->create(['product_id' => $this->product->id]);
    $since = now()->subMinute()->toIso8601String();

    $this->getJson('/api/v1/predict/updates?since='.urlencode($since), $this->siteHeaders)->assertOk()
        ->assertJsonCount(500, 'data.markets')
        ->assertJsonPath('data.has_more', false);

    $extra = PredictMarket::factory()->open()->create(['product_id' => $this->product->id]);

    $first = $this->getJson('/api/v1/predict/updates?since='.urlencode($since), $this->siteHeaders)->assertOk()
        ->assertJsonCount(500, 'data.markets')
        ->assertJsonPath('data.has_more', true);

    $next = $this->getJson('/api/v1/predict/updates?since='.urlencode($since).'&after_id='.$first->json('data.markets.499.id'), $this->siteHeaders)->assertOk()
        ->assertJsonPath('data.has_more', false);

    expect($next->json('data.markets.*.id'))->toBe([$extra->id]);
});

it('looks back three seconds before since, so a change next to the last server_time is not lost', function () {
    $market = PredictMarket::factory()->open()->create(['product_id' => $this->product->id]);
    $this->travel(1)->minutes();
    placePredictBet($this, $this->player, $market->id, 'yes', 100)->assertCreated();

    expect(changedMarketIds($this, now()->addSeconds(2)->toIso8601String()))->toBe([$market->id])
        ->and(changedMarketIds($this, now()->addSeconds(4)->toIso8601String()))->toBe([]);
});

it('refuses an after_id that is not a market id', function () {
    $this->getJson('/api/v1/predict/updates?since='.urlencode(now()->toIso8601String()).'&after_id=x', $this->siteHeaders)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('after_id');
});

it('links an imported market to its page on polymarket.com, and a custom market to nothing', function () {
    $event = PredictEvent::create(['source' => PredictMarket::SOURCE_POLYMARKET, 'external_event_id' => 'ev-1', 'slug' => 'epl-winner-2027', 'title' => 'EPL winner', 'category' => 'sport']);
    $imported = fixedMarket($this, '0.60', ['predict_event_id' => $event->id, 'external_slug' => 'will-arsenal-win-the-epl']);
    $withoutMarketSlug = fixedMarket($this, '0.60', ['predict_event_id' => $event->id, 'external_slug' => null]);
    $custom = PredictMarket::factory()->open()->create(['product_id' => $this->product->id]);

    $this->getJson("/api/v1/predict/markets/{$imported->id}", $this->siteHeaders)->assertOk()
        ->assertJsonPath('data.external_url', 'https://polymarket.com/event/epl-winner-2027/will-arsenal-win-the-epl');
    $this->getJson("/api/v1/predict/markets/{$withoutMarketSlug->id}", $this->siteHeaders)->assertOk()
        ->assertJsonPath('data.external_url', 'https://polymarket.com/event/epl-winner-2027');
    $this->getJson("/api/v1/predict/markets/{$custom->id}", $this->siteHeaders)->assertOk()
        ->assertJsonPath('data.external_url', null);
});

it('lists a player\'s settled bets: won, lost and void, not the open ones', function () {
    $market = PredictMarket::factory()->open()->create(['product_id' => $this->product->id]);
    $references = [];

    foreach (['open', 'won', 'lost', 'void'] as $status) {
        $references[$status] = placePredictBet($this, $this->player, $market->id, 'yes', 100)->assertCreated()->json('data.bet.reference');
        PredictBet::where('reference', $references[$status])->update(['status' => $status]);
    }

    $url = '/api/v1/customers/'.encryptId($this->player->id).'/predict/bets';

    expect($this->getJson("{$url}?status=settled", $this->siteHeaders)->assertOk()->json('data.items.*.reference'))
        ->toEqualCanonicalizing([$references['won'], $references['lost'], $references['void']])
        ->and($this->getJson("{$url}?status=open", $this->siteHeaders)->assertOk()->json('data.items.*.reference'))->toBe([$references['open']])
        ->and($this->getJson($url, $this->siteHeaders)->assertOk()->json('data.items'))->toHaveCount(4);
});

it('refuses an unknown bet status filter', function () {
    $this->getJson('/api/v1/customers/'.encryptId($this->player->id).'/predict/bets?status=pending', $this->siteHeaders)
        ->assertUnprocessable()
        ->assertJsonPath('code', 'validation_failed');
});

it('answers a rate limit on a prediction route in the error shape, retryable', function (string $limiter, string $method, string $path, string $headers) {
    RateLimiter::for($limiter, fn () => Limit::perMinute(1)->by('test'));
    $path = str_replace('{customer}', encryptId($this->player->id), $path);
    $this->json($method, $path, [], $this->{$headers});

    $this->json($method, $path, [], $this->{$headers})
        ->assertStatus(429)
        ->assertExactJson(['success' => false, 'message' => 'Too many requests', 'code' => 'rate_limited', 'retryable' => true])
        ->assertHeader('Retry-After');
})->with([
    'the polling feed' => ['predict-read', 'GET', '/api/v1/predict/updates', 'siteHeaders'],
    'a bet' => ['wagers', 'POST', '/api/v1/customers/{customer}/predict/bets', 'siteHeaders'],
    'a GMS write' => ['write', 'POST', '/api/v1/predict/admin/markets', 'adminHeaders'],
]);

it('refuses to delete a customer with an open prediction bet', function () {
    $market = PredictMarket::factory()->open()->create(['product_id' => $this->product->id]);
    $player = predictPlayer(100);
    placePredictBet($this, $player, $market->id, 'yes', 100)->assertCreated();

    $this->deleteJson('/api/v1/customers/'.encryptId($player->id), [], $this->adminHeaders)
        ->assertStatus(409)
        ->assertJson(['open' => ['open_prediction_bet']]);

    expect(Customer::find($player->id))->not->toBeNull();
});
