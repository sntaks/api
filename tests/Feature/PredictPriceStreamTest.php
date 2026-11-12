<?php

use App\Models\PredictOutcome;
use App\Models\PredictPriceSnapshot;
use App\Models\Product;
use App\Services\Predict\Polymarket\Stream\MarketConnection;
use App\Services\Predict\Polymarket\Stream\PriceStream;
use App\Services\Predict\Polymarket\Stream\StreamDisconnected;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    setUpPredict($this);
    $this->fixedProduct = Product::factory()->create(['game_id' => $this->product->game_id, 'code' => 'predict_fixed']);
    Cache::flush();
    config(['predict.polymarket.enabled' => true]);
    $this->player = predictPlayer();
});

/** A market-channel book snapshot in the live format (levels worst to best). */
function bookFrame(string $tokenId, string $bid, string $ask, string $size = '100000'): array
{
    return [
        'event_type' => 'book', 'asset_id' => $tokenId, 'market' => '0xcond',
        'bids' => [['price' => '0.01', 'size' => '5'], ['price' => $bid, 'size' => $size]],
        'asks' => [['price' => '0.99', 'size' => '5'], ['price' => $ask, 'size' => $size]],
    ];
}

/**
 * A scripted market channel: replays frames, then stays quiet. Records what the worker sent.
 */
class FakeMarketConnection implements MarketConnection
{
    public array $sent = [];

    public function __construct(public array $frames, public bool $dropAfterFrames = false) {}

    public function send(string $text): void
    {
        $this->sent[] = $text;
    }

    public function receive(): ?string
    {
        if ($this->frames !== []) {
            return json_encode(array_shift($this->frames));
        }

        if ($this->dropAfterFrames) {
            throw new StreamDisconnected('dropped');
        }

        usleep(20000);

        return null;
    }

    public function close(): void {}
}

it('keeps books from snapshots and level changes, in the live message formats', function () {
    $stream = new PriceStream;

    expect($stream->apply([bookFrame('t1', '0.61', '0.63'), bookFrame('t2', '0.37', '0.39')]))->toBe(['t1', 't2']);

    // A better bid arrives on t1; the old best ask is taken out
    $changed = $stream->apply(['event_type' => 'price_change', 'market' => '0xcond', 'price_changes' => [
        ['asset_id' => 't1', 'price' => '0.62', 'size' => '500', 'side' => 'BUY', 'best_bid' => '0.62', 'best_ask' => '0.63'],
        ['asset_id' => 't1', 'price' => '0.63', 'size' => '0', 'side' => 'SELL', 'best_bid' => '0.62', 'best_ask' => '0.99'],
        ['asset_id' => 'unknown', 'price' => '0.5', 'size' => '1', 'side' => 'BUY'],
    ]]);

    $book = $stream->summary('t1');
    expect($changed)->toBe(['t1'])
        ->and([$book->bestBid, $book->bestAsk])->toBe(['0.620000', '0.990000'])
        ->and($stream->knows('unknown'))->toBeFalse()
        ->and($stream->apply(['event_type' => 'new_market', 'id' => '1']))->toBe([]);
});

it('prices subscribed markets from the stream and marks the stream live', function () {
    $market = fixedMarket($this, '0.60');
    $yes = "tok-{$market->id}-yes";
    $no = "tok-{$market->id}-no";
    $connection = new FakeMarketConnection([[bookFrame($yes, '0.64', '0.66'), bookFrame($no, '0.34', '0.36')]]);
    app()->instance(MarketConnection::class, $connection);

    $this->artisan('predict:price-stream', ['--max-seconds' => 2])->assertSuccessful();

    $outcome = PredictOutcome::where('external_token_id', $yes)->sole();
    expect(json_decode($connection->sent[0], true))->toMatchArray(['type' => 'market'])
        ->and(json_decode($connection->sent[0], true)['assets_ids'])->toEqualCanonicalizing([$yes, $no])
        ->and((float) $outcome->midpoint)->toBe(0.65)
        ->and((float) $outcome->spread)->toBe(0.02)
        ->and($outcome->price_captured_at->gt(now()->subSeconds(5)))->toBeTrue()
        ->and(PriceStream::isLive())->toBeTrue()
        ->and(PredictPriceSnapshot::where('predict_outcome_id', $outcome->id)->exists())->toBeTrue();
});

it('allows only 10-second-old prices for betting while the stream is live', function () {
    $market = fixedMarket($this, '0.60');
    PredictOutcome::where('predict_market_id', $market->id)->update(['price_captured_at' => now()->subSeconds(11)]);

    quoteFor($this, $market)->assertCreated();

    PriceStream::beat();
    quoteFor($this, $market)->assertConflict()->assertJsonPath('reason', 'stale_price');
});

it('drops its books and reconnects when the connection is lost', function () {
    $market = fixedMarket($this, '0.60');
    $connects = 0;
    $connections = [
        new FakeMarketConnection([bookFrame("tok-{$market->id}-yes", '0.64', '0.66')], dropAfterFrames: true),
        new FakeMarketConnection([]),
    ];
    app()->bind(MarketConnection::class, function () use (&$connects, $connections) {
        return $connections[min($connects++, 1)];
    });

    $this->artisan('predict:price-stream', ['--max-seconds' => 3])->expectsOutputToContain('Connections 2')->assertSuccessful();
});

it('idles instead of exiting at once while the import is off', function () {
    config(['predict.polymarket.enabled' => false, 'predict.polymarket.stream_idle_seconds' => 1]);
    $started = microtime(true);

    $this->artisan('predict:price-stream')->assertSuccessful();

    expect(microtime(true) - $started)->toBeGreaterThanOrEqual(1.0);
});
