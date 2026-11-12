<?php

use App\Models\PredictAuditLog;
use App\Models\PredictMarket;
use App\Models\PredictOutcome;
use App\Models\PredictPriceSnapshot;
use App\Models\Product;
use App\Models\WagerRound;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const GAMMA = 'https://gamma-api.polymarket.com';
const CLOB = 'https://clob.polymarket.com';

beforeEach(function () {
    setUpPredict($this);
    Product::factory()->create(['game_id' => $this->product->game_id, 'code' => 'predict_fixed']);
    Cache::flush();
    config([
        'predict.polymarket.enabled' => true,
        'predict.polymarket.tags' => ['sport' => ['sports']],
        'predict.polymarket.retries' => 1,
        'predict.polymarket.retry_base_ms' => 0,
        'predict.polymarket.min_volume' => 1000,
    ]);
    fakeGamma([]);
    Http::fake([
        GAMMA.'/tags/slug/sports' => Http::response(['id' => '1', 'slug' => 'sports', 'label' => 'Sports']),
        GAMMA.'/events/keyset*' => fn () => Http::response(['events' => test()->gammaEvents]),
        CLOB.'/midpoints' => fn () => Http::response(test()->clobStatus === 200 ? test()->midpoints : ['error' => 'unavailable'], test()->clobStatus),
    ]);
});

/**
 * A binary Gamma market (v1 unless overridden).
 *
 * @return array<string, mixed>
 */
function gammaMarket(array $overrides = []): array
{
    return array_merge([
        'id' => '703257',
        'question' => 'Will Kenya win the 2027 Safari Rally?',
        'description' => 'Resolves YES if a Kenyan driver wins.',
        'slug' => 'kenya-safari-rally',
        'conditionId' => '0xcond',
        'outcomes' => '["Yes", "No"]',
        'clobTokenIds' => '["tok-yes", "tok-no"]',
        'version' => 'v1',
        'endDate' => now()->addDays(20)->toIso8601String(),
        'closed' => false,
        'volumeNum' => 25000,
        'liquidityNum' => 4000,
        'spread' => 0.02,
    ], $overrides);
}

/**
 * @param  list<array<string, mixed>>  $markets
 * @return array<string, mixed>
 */
function gammaEvent(array $markets, array $overrides = []): array
{
    return array_merge(['id' => '9001', 'slug' => 'safari-rally-2027', 'title' => 'Safari Rally 2027', 'markets' => $markets], $overrides);
}

/**
 * Point the faked Gamma and CLOB APIs at these events and midpoints. The fake is registered once (in
 * beforeEach) and reads this state, because a second Http::fake() would not replace the first stubs.
 */
function fakeGamma(array $events, array $prices = ['tok-yes' => '0.62', 'tok-no' => '0.38'], int $clobStatus = 200): void
{
    test()->gammaEvents = $events;
    test()->midpoints = $prices;
    test()->clobStatus = $clobStatus;
}

/** Import the fake events, then price them. */
function importAndPrice(object $test): PredictMarket
{
    $test->artisan('predict:sync-events')->assertSuccessful();
    $test->artisan('predict:sync-prices')->assertSuccessful();

    return PredictMarket::where('source', 'polymarket')->firstOrFail();
}

function openImported(object $test): PredictMarket
{
    $market = importAndPrice($test);
    $test->postJson("/api/v1/predict/admin/markets/{$market->id}/publish", ['admin_user' => 'ops'], $test->adminHeaders)->assertOk();

    return $market->fresh();
}

it('imports binary markets as drafts with their tokens, asking only for open events', function () {
    fakeGamma([gammaEvent([gammaMarket()])]);

    $this->artisan('predict:sync-events')->assertSuccessful();

    $market = PredictMarket::with('outcomes')->sole();
    expect($market->only(['source', 'status', 'engine', 'category', 'external_market_id', 'protocol_version']))
        ->toBe(['source' => 'polymarket', 'status' => 'draft', 'engine' => 'reference', 'category' => 'sport', 'external_market_id' => '703257', 'protocol_version' => 'v1'])
        ->and($market->resolution_source)->toBe('https://polymarket.com/event/safari-rally-2027')
        ->and($market->outcomes->pluck('external_token_id', 'side')->all())->toMatchArray(['yes' => 'tok-yes', 'no' => 'tok-no']);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/events/keyset') && $request['closed'] === 'false' && $request['tag_id'] === '1');
});

it('reads v2 tokens from positionIds', function () {
    fakeGamma([gammaEvent([gammaMarket(['version' => 'v2', 'clobTokenIds' => null, 'positionIds' => ['pos-yes', 'pos-no']])])]);

    $this->artisan('predict:sync-events')->assertSuccessful();

    expect(PredictOutcome::pluck('external_token_id', 'side')->all())->toMatchArray(['yes' => 'pos-yes', 'no' => 'pos-no']);
});

it('skips markets it cannot use', function (array $overrides) {
    fakeGamma([gammaEvent([gammaMarket($overrides)])]);

    $this->artisan('predict:sync-events')->assertSuccessful();

    expect(PredictMarket::count())->toBe(0);
})->with([
    'three outcomes' => [['outcomes' => '["A", "B", "C"]', 'clobTokenIds' => '["a", "b", "c"]']],
    'unsupported protocol' => [['version' => 'v3']],
    'below the minimum volume' => [['volumeNum' => 10]],
    'already closed' => [['closed' => true]],
    'ending in the past' => [['endDate' => now()->subDay()->toIso8601String()]],
]);

it('follows the cursor until the last page', function () {
    $pages = [
        ['events' => [gammaEvent([gammaMarket()])], 'next_cursor' => 'page-2'],
        ['events' => [gammaEvent([gammaMarket(['id' => '703258', 'clobTokenIds' => '["t3", "t4"]'])], ['id' => '9002'])]],
    ];
    Http::swap(new Factory);
    Http::fake([
        GAMMA.'/tags/slug/sports' => Http::response(['id' => '1']),
        GAMMA.'/events/keyset*' => Http::sequence()->push($pages[0])->push($pages[1]),
    ]);

    $this->artisan('predict:sync-events')->assertSuccessful();

    expect(PredictMarket::count())->toBe(2);
    Http::assertSent(fn (Request $request) => ($request['after_cursor'] ?? null) === 'page-2');
});

it('refreshes a known market but keeps what an admin localised', function () {
    fakeGamma([gammaEvent([gammaMarket()])]);
    $this->artisan('predict:sync-events')->assertSuccessful();
    $market = PredictMarket::sole();
    $this->putJson("/api/v1/predict/admin/markets/{$market->id}", ['admin_user' => 'ops', 'question' => 'Will a Kenyan win the Safari Rally?'], $this->adminHeaders)->assertOk();

    fakeGamma([gammaEvent([gammaMarket(['question' => 'Renamed upstream', 'description' => 'New rules', 'volumeNum' => 50000])])]);
    $this->artisan('predict:sync-events')->assertSuccessful();

    $market->refresh();
    expect($market->question)->toBe('Will a Kenyan win the Safari Rally?')
        ->and($market->external_question)->toBe('Renamed upstream')
        ->and($market->description)->toBe('New rules')
        ->and((float) $market->external_volume)->toBe(50000.0)
        ->and(PredictMarket::count())->toBe(1);
});

it('closes an open market and rejects a draft once Polymarket closes them', function () {
    fakeGamma([gammaEvent([gammaMarket(), gammaMarket(['id' => '703258', 'clobTokenIds' => '["t3", "t4"]'])])], ['tok-yes' => '0.6', 'tok-no' => '0.4', 't3' => '0.5', 't4' => '0.5']);
    $open = openImported($this);
    $draft = PredictMarket::where('external_market_id', '703258')->sole();

    fakeGamma([gammaEvent([gammaMarket(['closed' => true]), gammaMarket(['id' => '703258', 'clobTokenIds' => '["t3", "t4"]', 'closed' => true])])]);
    $this->artisan('predict:sync-events')->assertSuccessful();

    expect($open->fresh()->status)->toBe(PredictMarket::STATUS_CLOSED)
        ->and($draft->fresh()->status)->toBe(PredictMarket::STATUS_REJECTED);
});

it('records reliable reference prices and a history point', function () {
    fakeGamma([gammaEvent([gammaMarket()])]);

    $market = importAndPrice($this);

    $yes = $market->outcomes()->where('side', 'yes')->sole();
    expect((float) $yes->midpoint)->toBe(0.62)
        ->and($yes->price_reliable)->toBeTrue()
        ->and(PredictPriceSnapshot::where('predict_outcome_id', $yes->id)->count())->toBe(1);
});

it('does not trust the price of a market that never traded', function () {
    fakeGamma([gammaEvent([gammaMarket(['volumeNum' => 0])])]);
    config(['predict.polymarket.min_volume' => 0]);

    $market = importAndPrice($this);

    expect($market->outcomes()->where('price_reliable', true)->exists())->toBeFalse();
});

it('approves an imported draft only once it has usable prices', function () {
    fakeGamma([gammaEvent([gammaMarket()])]);
    $this->artisan('predict:sync-events')->assertSuccessful();
    $market = PredictMarket::sole();

    $this->postJson("/api/v1/predict/admin/markets/{$market->id}/publish", ['admin_user' => 'ops'], $this->adminHeaders)
        ->assertConflict()
        ->assertJsonPath('code', 'price_unavailable');

    $this->artisan('predict:sync-prices')->assertSuccessful();

    $this->postJson("/api/v1/predict/admin/markets/{$market->id}/publish", ['admin_user' => 'ops'], $this->adminHeaders)
        ->assertOk()
        ->assertJsonPath('data.market.status', 'open')
        ->assertJsonPath('data.market.external.tokens.yes', 'tok-yes');

    expect(WagerRound::where('reference', $market->round_reference)->exists())->toBeTrue();
});

it('rejects a draft with a reason and never imports it again', function () {
    fakeGamma([gammaEvent([gammaMarket()])]);
    $this->artisan('predict:sync-events')->assertSuccessful();
    $market = PredictMarket::sole();

    $this->postJson("/api/v1/predict/admin/markets/{$market->id}/reject", ['admin_user' => 'ops', 'reason' => 'Not relevant in Kenya'], $this->adminHeaders)
        ->assertOk()
        ->assertJsonPath('data.market.status', 'rejected');
    $this->artisan('predict:sync-events')->assertSuccessful();

    expect(PredictMarket::count())->toBe(1)
        ->and($market->fresh()->status)->toBe(PredictMarket::STATUS_REJECTED);
});

it('shows players the reference prices of an open imported market, with no betting', function () {
    fakeGamma([gammaEvent([gammaMarket()])]);
    $market = openImported($this);

    $this->getJson("/api/v1/predict/markets/{$market->id}", $this->siteHeaders)
        ->assertOk()
        ->assertJsonPath('data.source', 'polymarket')
        ->assertJsonPath('data.betting_available', false)
        ->assertJsonPath('data.event.title', 'Safari Rally 2027')
        ->assertJsonPath('data.odds.is_reference', true)
        ->assertJsonPath('data.odds.yes_probability', 0.62)
        ->assertJsonPath('data.odds.yes_multiplier', null)
        ->assertJsonPath('data.odds.attribution', 'Reference prices from Polymarket');

    $this->getJson("/api/v1/predict/markets/{$market->id}/history", $this->siteHeaders)
        ->assertOk()
        ->assertJsonPath('data.points.0.yes_probability', 0.62);
});

it('refuses bets and estimates on an imported market', function () {
    fakeGamma([gammaEvent([gammaMarket()])]);
    $market = openImported($this);

    placePredictBet($this, predictPlayer(), $market->id, 'yes', 100)
        ->assertConflict()
        ->assertJsonPath('code', 'betting_not_available');
    $this->getJson("/api/v1/predict/markets/{$market->id}/estimate?side=yes&stake=100", $this->siteHeaders)
        ->assertConflict()
        ->assertJsonPath('code', 'betting_not_available');
});

it('suspends a market whose price disappears and reopens it when the price returns', function () {
    fakeGamma([gammaEvent([gammaMarket()])]);
    $market = openImported($this);

    fakeGamma([], ['tok-no' => '0.38']);
    $this->artisan('predict:sync-prices')->assertSuccessful();
    expect($market->fresh()->only(['status', 'suspension_cause']))->toBe(['status' => 'suspended', 'suspension_cause' => 'price']);

    fakeGamma([]);
    $this->artisan('predict:sync-prices')->assertSuccessful();
    expect($market->fresh()->status)->toBe(PredictMarket::STATUS_OPEN)
        ->and(PredictAuditLog::where('admin_user', 'price-sync')->pluck('action')->all())->toBe(['suspend', 'resume']);
});

it('leaves a market an admin suspended alone when prices recover', function () {
    fakeGamma([gammaEvent([gammaMarket()])]);
    $market = openImported($this);
    $this->postJson("/api/v1/predict/admin/markets/{$market->id}/suspend", ['admin_user' => 'ops'], $this->adminHeaders)->assertOk();

    $this->artisan('predict:sync-prices')->assertSuccessful();

    expect($market->fresh()->only(['status', 'suspension_cause']))->toBe(['status' => 'suspended', 'suspension_cause' => 'admin']);
});

it('suspends every open imported market when Polymarket is down', function () {
    fakeGamma([gammaEvent([gammaMarket()])]);
    $market = openImported($this);
    fakeGamma([], clobStatus: 500);

    $this->artisan('predict:sync-prices')->assertFailed();

    expect($market->fresh()->only(['status', 'suspension_cause']))->toBe(['status' => 'suspended', 'suspension_cause' => 'price']);
});

it('stops calling Polymarket while the circuit is open', function () {
    fakeGamma([gammaEvent([gammaMarket()])]);
    $market = openImported($this);
    config(['predict.polymarket.circuit_failures' => 2]);
    fakeGamma([], clobStatus: 503);
    $midpointCalls = fn () => Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/midpoints'))->count();
    $before = $midpointCalls();

    $this->artisan('predict:sync-prices')->assertFailed();
    $this->artisan('predict:sync-prices')->assertFailed();
    $this->artisan('predict:sync-prices')->assertFailed();

    expect($midpointCalls() - $before)->toBe(2);
});

it('does nothing while the Polymarket import is switched off', function () {
    config(['predict.polymarket.enabled' => false]);

    $this->artisan('predict:sync-events')->assertSuccessful();
    $this->artisan('predict:sync-prices')->assertSuccessful();

    Http::assertNothingSent();
});

it('prunes price history past the kept window', function () {
    fakeGamma([gammaEvent([gammaMarket()])]);
    $market = importAndPrice($this);
    $outcome = $market->outcomes()->where('side', 'yes')->sole();
    PredictPriceSnapshot::create(['predict_outcome_id' => $outcome->id, 'midpoint' => '0.5', 'captured_at' => now()->subDays(31)]);

    $this->artisan('predict:prune-snapshots')->assertSuccessful();

    expect(PredictPriceSnapshot::count())->toBe(1);
});
