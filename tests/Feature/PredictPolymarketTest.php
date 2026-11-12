<?php

use App\Models\PredictAuditLog;
use App\Models\PredictMarket;
use App\Models\PredictOutcome;
use App\Models\PredictPriceSnapshot;
use App\Models\PredictQuote;
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
        CLOB.'/books' => fn (Request $request) => test()->clobStatus === 200
            ? Http::response(fakeBooks($request->data(), test()->midpoints))
            : Http::response(['error' => 'unavailable'], test()->clobStatus),
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
        'outcomePrices' => '["0.6", "0.4"]',
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

/**
 * CLOB order books for the requested tokens that have a test price: one bid level 0.01 below it and one ask
 * level 0.01 above it, deep enough to pass the betting guards (levels worst to best, as the real API sends them).
 *
 * @param  list<array{token_id: string}>  $requested
 * @param  array<string, string>  $midpoints
 * @return list<array<string, mixed>>
 */
function fakeBooks(array $requested, array $midpoints, string $size = '100000'): array
{
    return collect($requested)
        ->filter(fn (array $book) => isset($midpoints[$book['token_id']]))
        ->map(fn (array $book) => [
            'asset_id' => $book['token_id'],
            'market' => '0xcond',
            'bids' => [['price' => '0.01', 'size' => '10'], ['price' => bcsub($midpoints[$book['token_id']], '0.01', 2), 'size' => $size]],
            'asks' => [['price' => '0.99', 'size' => '10'], ['price' => bcadd($midpoints[$book['token_id']], '0.01', 2), 'size' => $size]],
        ])
        ->values()
        ->all();
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

it('asks Polymarket for gzip, which large event pages require', function () {
    fakeGamma([gammaEvent([gammaMarket()])]);

    $this->artisan('predict:sync-events')->assertSuccessful();

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/events/keyset') && $request->hasHeader('Accept-Encoding', 'gzip'));
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

it('prices drafts from Gamma without keeping history or calling the CLOB', function () {
    fakeGamma([gammaEvent([gammaMarket()])]);

    $market = importAndPrice($this);

    $yes = $market->outcomes()->where('side', 'yes')->sole();
    expect((float) $yes->midpoint)->toBe(0.6)
        ->and($yes->price_reliable)->toBeTrue()
        ->and(PredictPriceSnapshot::count())->toBe(0);
    Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/books'));
});

it('prices live markets from the CLOB and keeps their history', function () {
    fakeGamma([gammaEvent([gammaMarket()])]);
    $market = openImported($this);

    $this->artisan('predict:sync-prices')->assertSuccessful();

    $yes = $market->outcomes()->where('side', 'yes')->sole();
    expect((float) $yes->midpoint)->toBe(0.62)
        ->and(PredictPriceSnapshot::where('predict_outcome_id', $yes->id)->count())->toBe(1);
});

it('does not trust the price of a market that never traded', function () {
    fakeGamma([gammaEvent([gammaMarket(['volumeNum' => 0])])]);
    config(['predict.polymarket.min_volume' => 0]);

    $market = importAndPrice($this);

    expect($market->outcomes()->where('price_reliable', true)->exists())->toBeFalse();
});

it('approves an imported draft on fresh CLOB prices', function () {
    fakeGamma([gammaEvent([gammaMarket()])]);
    $this->artisan('predict:sync-events')->assertSuccessful();
    $market = PredictMarket::sole();

    $this->postJson("/api/v1/predict/admin/markets/{$market->id}/publish", ['admin_user' => 'ops'], $this->adminHeaders)
        ->assertOk()
        ->assertJsonPath('data.market.status', 'open')
        ->assertJsonPath('data.market.odds.yes_probability', 0.62)
        ->assertJsonPath('data.market.external.tokens.yes', 'tok-yes');

    expect(WagerRound::where('reference', $market->round_reference)->exists())->toBeTrue();
});

it('refuses to approve an imported draft the CLOB has no price for', function () {
    fakeGamma([gammaEvent([gammaMarket()])], ['tok-no' => '0.38']);
    $this->artisan('predict:sync-events')->assertSuccessful();
    $market = PredictMarket::sole();

    $this->postJson("/api/v1/predict/admin/markets/{$market->id}/publish", ['admin_user' => 'ops'], $this->adminHeaders)
        ->assertConflict()
        ->assertJsonPath('code', 'price_unavailable');
});

it('asks to retry an approval while Polymarket is down', function () {
    fakeGamma([gammaEvent([gammaMarket()])], clobStatus: 503);
    $this->artisan('predict:sync-events')->assertSuccessful();
    $market = PredictMarket::sole();

    $this->postJson("/api/v1/predict/admin/markets/{$market->id}/publish", ['admin_user' => 'ops'], $this->adminHeaders)
        ->assertStatus(503)
        ->assertJsonPath('code', 'polymarket_unavailable')
        ->assertJsonPath('retryable', true);

    expect($market->fresh()->status)->toBe(PredictMarket::STATUS_DRAFT);
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

it('shows players the reference prices and the fixed odds on offer', function () {
    fakeGamma([gammaEvent([gammaMarket()])]);
    $market = openImported($this);
    $this->artisan('predict:sync-prices')->assertSuccessful();

    // Book 0.61 / 0.63 → mid 0.62; offer 0.62 × 1.05 = 0.651 → 1.53x
    $this->getJson("/api/v1/predict/markets/{$market->id}", $this->siteHeaders)
        ->assertOk()
        ->assertJsonPath('data.source', 'polymarket')
        ->assertJsonPath('data.betting_available', true)
        ->assertJsonPath('data.event.title', 'Safari Rally 2027')
        ->assertJsonPath('data.odds.is_reference', true)
        ->assertJsonPath('data.odds.yes_probability', 0.62)
        ->assertJsonPath('data.odds.yes_multiplier', 1.53)
        ->assertJsonPath('data.odds.attribution', 'Reference prices from Polymarket');

    $this->getJson("/api/v1/predict/markets/{$market->id}/history", $this->siteHeaders)
        ->assertOk()
        ->assertJsonPath('data.points.0.yes_probability', 0.62);
});

it('refuses a bet on an imported market without a quote', function () {
    fakeGamma([gammaEvent([gammaMarket()])]);
    $market = openImported($this);

    placePredictBet($this, predictPlayer(), $market->id, 'yes', 100)
        ->assertUnprocessable()
        ->assertJsonPath('code', 'quote_required');
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
    $bookCalls = fn () => Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/books'))->count();
    $before = $bookCalls();

    $this->artisan('predict:sync-prices')->assertFailed();
    $this->artisan('predict:sync-prices')->assertFailed();
    $this->artisan('predict:sync-prices')->assertFailed();

    expect($bookCalls() - $before)->toBe(2);
});

it('does nothing while the Polymarket import is switched off', function () {
    config(['predict.polymarket.enabled' => false]);

    $this->artisan('predict:sync-events')->assertSuccessful();
    $this->artisan('predict:sync-prices')->assertSuccessful();

    Http::assertNothingSent();
});

it('prunes price history past the kept window, and any left on drafts', function () {
    fakeGamma([gammaEvent([gammaMarket(), gammaMarket(['id' => '703258', 'clobTokenIds' => '["t3", "t4"]'])])], ['tok-yes' => '0.6', 'tok-no' => '0.4']);
    $live = openImported($this);
    $this->artisan('predict:sync-prices')->assertSuccessful();
    $liveYes = $live->outcomes()->where('side', 'yes')->sole();
    $draftYes = PredictMarket::where('external_market_id', '703258')->sole()->outcomes()->where('side', 'yes')->sole();
    PredictPriceSnapshot::create(['predict_outcome_id' => $liveYes->id, 'midpoint' => '0.5', 'captured_at' => now()->subDays(31)]);
    PredictPriceSnapshot::create(['predict_outcome_id' => $draftYes->id, 'midpoint' => '0.5', 'captured_at' => now()]);

    $this->artisan('predict:prune-snapshots')->assertSuccessful();

    expect(PredictPriceSnapshot::pluck('predict_outcome_id')->all())->toBe([$liveYes->id]);
});

it('prunes quotes older than a day', function () {
    $this->fixedProduct = Product::where('code', 'predict_fixed')->sole();
    $market = fixedMarket($this);
    $player = predictPlayer();
    $old = PredictQuote::create(['predict_market_id' => $market->id, 'customer_id' => $player->id, 'side' => 'yes', 'stake_minor' => 1000, 'p_fair' => '0.6', 'p_offer' => '0.63', 'multiplier_x100' => 158, 'expires_at' => now()->subDays(2)]);
    $old->forceFill(['created_at' => now()->subDays(2)])->save();
    PredictQuote::create(['predict_market_id' => $market->id, 'customer_id' => $player->id, 'side' => 'yes', 'stake_minor' => 1000, 'p_fair' => '0.6', 'p_offer' => '0.63', 'multiplier_x100' => 158, 'expires_at' => now()]);

    $this->artisan('predict:prune-snapshots')->assertSuccessful();

    expect(PredictQuote::count())->toBe(1);
});

function bulkMarkets(): void
{
    fakeGamma([gammaEvent([
        gammaMarket(['id' => 'm1', 'clobTokenIds' => '["a1", "a2"]', 'volumeNum' => 5000]),
        gammaMarket(['id' => 'm2', 'clobTokenIds' => '["b1", "b2"]', 'volumeNum' => 50000]),
        gammaMarket(['id' => 'm3', 'clobTokenIds' => '["c1", "c2"]', 'volumeNum' => 500000]),
    ])], ['a1' => '0.3', 'a2' => '0.7', 'b1' => '0.4', 'b2' => '0.6', 'c1' => '0.5', 'c2' => '0.5']);
    test()->artisan('predict:sync-events')->assertSuccessful();
}

it('rejects every draft below a volume in one call, logging each', function () {
    bulkMarkets();

    $this->postJson('/api/v1/predict/admin/markets/bulk/reject', [
        'admin_user' => 'ops', 'reason' => 'Too little volume', 'filter' => ['source' => 'polymarket', 'max_volume' => 100000],
    ], $this->adminHeaders)
        ->assertOk()
        ->assertJsonCount(2, 'data.done')
        ->assertJsonPath('data.remaining', 0);

    expect(PredictMarket::where('status', 'rejected')->pluck('external_market_id')->sort()->values()->all())->toBe(['m1', 'm2'])
        ->and(PredictAuditLog::where('action', 'reject')->count())->toBe(2);
});

it('approves chosen drafts on one batch of fresh prices', function () {
    bulkMarkets();
    $ids = PredictMarket::whereIn('external_market_id', ['m2', 'm3'])->pluck('id')->all();

    $this->postJson('/api/v1/predict/admin/markets/bulk/publish', ['admin_user' => 'ops', 'ids' => $ids], $this->adminHeaders)
        ->assertOk()
        ->assertJsonCount(2, 'data.done')
        ->assertJsonPath('data.refused', []);

    expect(PredictMarket::where('status', 'open')->count())->toBe(2);
    expect(Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/books'))->count())->toBe(1);
});

it('handles at most the limit per call and says how many drafts are left', function () {
    bulkMarkets();

    $this->postJson('/api/v1/predict/admin/markets/bulk/reject', [
        'admin_user' => 'ops', 'reason' => 'Clean-up', 'filter' => ['source' => 'polymarket'], 'limit' => 2,
    ], $this->adminHeaders)
        ->assertOk()
        ->assertJsonCount(2, 'data.done')
        ->assertJsonPath('data.remaining', 1);
});

it('reports the drafts it could not approve and approves the rest', function () {
    bulkMarkets();
    fakeGamma(test()->gammaEvents, ['a1' => '0.3', 'a2' => '0.7', 'b1' => '0.4', 'b2' => '0.6']);
    $m3 = PredictMarket::where('external_market_id', 'm3')->value('id');

    $this->postJson('/api/v1/predict/admin/markets/bulk/publish', ['admin_user' => 'ops', 'filter' => ['source' => 'polymarket']], $this->adminHeaders)
        ->assertOk()
        ->assertJsonCount(2, 'data.done')
        ->assertJsonPath('data.refused', [['id' => $m3, 'code' => 'price_unavailable']]);
});

it('needs ids or a filter, and a reason to reject', function () {
    $this->postJson('/api/v1/predict/admin/markets/bulk/reject', ['admin_user' => 'ops'], $this->adminHeaders)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['ids', 'filter', 'reason']);
});
