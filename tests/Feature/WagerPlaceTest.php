<?php

use App\Jobs\SendWalletWebhookJob;
use App\Models\ApiKey;
use App\Models\Customer;
use App\Models\Game;
use App\Models\LedgerEntry;
use App\Models\Product;
use App\Models\Wager;
use App\Models\WagerRound;
use App\Models\Wallet;
use App\Services\PlayerTokenService;
use App\Services\WagerService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->game = Game::factory()->create(['code' => 'crash']);
    $this->key = ApiKey::create(['key' => 'game_crash_key', 'name' => 'crash', 'scope' => ApiKey::SCOPE_GAME, 'game_id' => $this->game->id, 'is_active' => true]);
    $this->product = Product::factory()->create(['game_id' => $this->game->id, 'code' => 'crash_classic', 'max_stake' => 500]);
    $this->player = Customer::factory()->create(['status' => 1]);
    $this->wallet = Wallet::factory()->create(['customer_id' => $this->player->id, 'balance' => 300]);
    $this->token = app(PlayerTokenService::class)->issue($this->player, $this->game)->plainTextToken;
});

function placeWager(object $test, array $overrides = [], ?string $token = null, bool $withToken = true)
{
    $payload = array_merge(['product' => 'crash_classic', 'reference' => 'bet-1', 'funding' => 'cash', 'stake' => 100], $overrides);
    $headers = apiHeaders($test->key->key) + ($withToken ? ['Authorization' => 'Bearer '.($token ?? $test->token)] : []);

    return $test->postJson('/api/v1/wagers', array_filter($payload, fn ($value) => $value !== null), $headers);
}

it('debits the stake into the wager escrow', function () {
    $version = $this->wallet->fresh()->wallet_version;

    $response = placeWager($this, ['round_reference' => 'round-1', 'channel' => 'web', 'metadata' => ['seat' => 3]])
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.replayed', false)
        ->assertJsonPath('data.wager.reference', 'bet-1')
        ->assertJsonPath('data.wager.product', 'crash_classic')
        ->assertJsonPath('data.wager.funding', 'cash')
        ->assertJsonPath('data.wager.stake', 100)
        ->assertJsonPath('data.wager.status', 'open')
        ->assertJsonPath('data.wager.settlement_version', 0)
        ->assertJsonPath('data.wager.round_reference', 'round-1')
        ->assertJsonPath('data.balance.balance', 200)
        ->assertJsonPath('data.balance.wallet_version', $version + 1);

    $wager = Wager::firstOrFail();
    expect((float) $wager->balance)->toBe(100.0)
        ->and($wager->customer_id)->toBe($this->player->id)
        ->and($wager->wallet_id)->toBe($this->wallet->id)
        ->and($wager->api_key_id)->toBe($this->key->id)
        ->and($wager->channel)->toBe('web')
        ->and($wager->metadata)->toBe(['seat' => 3])
        ->and($wager->placed_at)->not->toBeNull()
        ->and($wager->round->reference)->toBe('round-1')
        ->and($wager->round->status)->toBe(WagerRound::STATUS_OPEN)
        ->and((float) $this->wallet->fresh()->balance)->toBe(200.0);

    $entries = LedgerEntry::where('entry_type', 'wager_stake')->orderBy('id')->get();
    expect($entries)->toHaveCount(2)
        ->and($entries->first()->wallet_type)->toBe('wallet')
        ->and((float) $entries->first()->debit)->toBe(100.0)
        ->and($entries->last()->wallet_type)->toBe('wager')
        ->and($entries->last()->wallet_id)->toBe($wager->id)
        ->and((float) $entries->last()->credit)->toBe(100.0)
        ->and($entries->first()->product_id)->toBe($this->product->id);
    expect($response->json('data.wager.placed_at'))->not->toBeNull();
});

it('replays a place with the same reference and payload', function () {
    placeWager($this)->assertCreated();
    $version = $this->wallet->fresh()->wallet_version;

    placeWager($this)
        ->assertOk()
        ->assertJsonPath('data.replayed', true)
        ->assertJsonPath('data.balance.balance', 200)
        ->assertJsonPath('data.balance.wallet_version', $version);

    expect(Wager::count())->toBe(1)
        ->and(LedgerEntry::where('entry_type', 'wager_stake')->count())->toBe(2)
        ->and((float) $this->wallet->fresh()->balance)->toBe(200.0);
});

it('refuses the same reference with a different stake', function () {
    placeWager($this)->assertCreated();

    placeWager($this, ['stake' => 50])
        ->assertStatus(409)
        ->assertJson(['success' => false, 'code' => 'reference_conflict', 'retryable' => false]);

    expect((float) $this->wallet->fresh()->balance)->toBe(200.0)
        ->and(Wager::count())->toBe(1);
});

it('rejects a place whose reference was tombstoned by a void', function () {
    Wager::factory()->create([
        'product_id' => $this->product->id, 'reference' => 'bet-1', 'status' => Wager::STATUS_VOID,
        'is_tombstone' => true, 'stake' => 0, 'balance' => 0,
    ]);

    placeWager($this)
        ->assertStatus(409)
        ->assertJson(['code' => 'wager_voided', 'retryable' => false]);

    expect((float) $this->wallet->fresh()->balance)->toBe(300.0);
});

it('refuses a place without a player token', function () {
    placeWager($this, withToken: false)->assertUnauthorized()->assertJsonPath('code', 'invalid_player_token');

    expect(Wager::count())->toBe(0);
});

it('refuses a stake above the product limit', function () {
    $this->wallet->update(['balance' => 1000]);

    placeWager($this, ['stake' => 500.01])
        ->assertStatus(422)
        ->assertJson(['code' => 'stake_above_limit', 'retryable' => false]);

    placeWager($this, ['stake' => 500, 'reference' => 'bet-2'])->assertCreated();
});

it('refuses a stake while the balance is negative', function () {
    $this->wallet->update(['balance' => -5]);

    placeWager($this, ['stake' => 1])
        ->assertStatus(422)
        ->assertJson(['code' => 'insufficient_funds', 'retryable' => false]);

    expect(Wager::count())->toBe(0)
        ->and((float) $this->wallet->fresh()->balance)->toBe(-5.0);
});

it('refuses a stake above the balance', function () {
    placeWager($this, ['stake' => 300.01])->assertStatus(422)->assertJsonPath('code', 'insufficient_funds');

    placeWager($this, ['stake' => 300])->assertCreated();
    expect((float) $this->wallet->fresh()->balance)->toBe(0.0);
});

it('places a free bet without moving money', function () {
    $version = $this->wallet->fresh()->wallet_version;

    placeWager($this, ['funding' => 'free_bet', 'stake' => null, 'free_bet_amount' => 40])
        ->assertCreated()
        ->assertJsonPath('data.wager.funding', 'free_bet')
        ->assertJsonPath('data.wager.stake', 0)
        ->assertJsonPath('data.wager.free_bet_amount', 40)
        ->assertJsonPath('data.balance.balance', 300)
        ->assertJsonPath('data.balance.wallet_version', $version);

    expect(Wager::firstOrFail()->funding)->toBe('free_bet')
        ->and(LedgerEntry::count())->toBe(0)
        ->and((float) $this->wallet->fresh()->balance)->toBe(300.0);

    placeWager($this, ['funding' => 'free_bet', 'stake' => null, 'free_bet_amount' => 40])
        ->assertOk()
        ->assertJsonPath('data.replayed', true);
});

it('places a free bet for a player in debt', function () {
    $this->wallet->update(['balance' => -20]);

    placeWager($this, ['funding' => 'free_bet', 'stake' => null, 'free_bet_amount' => 10])->assertCreated();
});

it('handles two concurrent places with the same reference once', function () {
    // The other request got there first: its row exists when ours reaches the database.
    Wager::factory()->create([
        'product_id' => $this->product->id, 'customer_id' => $this->player->id, 'wallet_id' => $this->wallet->id,
        'reference' => 'bet-1', 'stake' => 100, 'balance' => 100,
    ]);

    placeWager($this)->assertOk()->assertJsonPath('data.replayed', true);
    placeWager($this, ['stake' => 70])->assertStatus(409)->assertJsonPath('code', 'reference_conflict');

    expect(Wager::count())->toBe(1)
        ->and(LedgerEntry::count())->toBe(0)
        ->and((float) $this->wallet->fresh()->balance)->toBe(300.0);
});

it('refuses a reference that belongs to another player', function () {
    $other = Customer::factory()->create(['status' => 1]);
    Wager::factory()->create(['product_id' => $this->product->id, 'customer_id' => $other->id, 'reference' => 'bet-1', 'stake' => 100]);

    placeWager($this)->assertStatus(409)->assertJsonPath('code', 'reference_conflict');
});

it('sends a wallet webhook to kadi after a stake', function () {
    Cache::flush();
    Queue::fake();
    config([
        'wallet-webhook.enabled' => true,
        'wallet-webhook.url' => 'https://kadi-site.test/api/v1/wallet-webhooks',
        'wallet-webhook.secret' => str_repeat('a', 32),
    ]);

    placeWager($this)->assertCreated();

    Queue::assertPushed(SendWalletWebhookJob::class);
});

it('refuses a product of another game', function () {
    $other = Product::factory()->create(['code' => 'sports_1x2']);

    placeWager($this, ['product' => $other->code])
        ->assertForbidden()
        ->assertJson(['success' => false, 'code' => 'product_not_allowed', 'retryable' => false]);

    placeWager($this, ['product' => 'no_such_product'])->assertForbidden()->assertJsonPath('code', 'product_not_allowed');
    expect(Wager::count())->toBe(0);
});

it('refuses a rake product of the key\'s own game', function () {
    $kadi = Game::where('code', 'kadi')->firstOrFail();
    $kadiKey = ApiKey::create(['key' => 'game_kadi_key', 'name' => 'kadi', 'scope' => ApiKey::SCOPE_GAME, 'game_id' => $kadi->id, 'is_active' => true]);
    $token = app(PlayerTokenService::class)->issue($this->player, $kadi)->plainTextToken;

    $this->postJson('/api/v1/wagers', ['product' => 'kadi', 'reference' => 'bet-1', 'funding' => 'cash', 'stake' => 100], apiHeaders($kadiKey->key) + ['Authorization' => 'Bearer '.$token])
        ->assertForbidden()
        ->assertJson(['success' => false, 'code' => 'product_not_allowed', 'retryable' => false]);

    expect(Wager::count())->toBe(0)->and(LedgerEntry::count())->toBe(0);
});

it('refuses a product that is not active', function () {
    $this->product->update(['status' => Product::STATUS_SUSPENDED]);

    placeWager($this)->assertForbidden()->assertJson(['code' => 'product_not_active', 'retryable' => false]);
    expect(Wager::count())->toBe(0);
});

it('refuses an excluded player', function () {
    $this->player->exclusions()->create(['type' => 'break', 'duration' => '24h', 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay()]);

    placeWager($this)
        ->assertForbidden()
        ->assertJson(['code' => 'player_excluded', 'retryable' => false])
        ->assertJsonStructure(['excluded_until']);

    expect(Wager::count())->toBe(0)->and((float) $this->wallet->fresh()->balance)->toBe(300.0);
});

it('refuses a place into a settled or voided round', function (string $status) {
    WagerRound::factory()->create(['product_id' => $this->product->id, 'reference' => 'round-1', 'status' => $status, 'closed_at' => now()]);

    placeWager($this, ['round_reference' => 'round-1'])
        ->assertStatus(409)
        ->assertJson(['code' => 'round_closed', 'retryable' => false]);

    expect(Wager::count())->toBe(0)->and((float) $this->wallet->fresh()->balance)->toBe(300.0);
})->with([WagerRound::STATUS_SETTLED, WagerRound::STATUS_VOIDED]);

it('joins an open round that already exists', function () {
    $round = WagerRound::factory()->create(['product_id' => $this->product->id, 'reference' => 'round-1']);

    placeWager($this, ['round_reference' => 'round-1'])->assertCreated();
    placeWager($this, ['round_reference' => 'round-1', 'reference' => 'bet-2'])->assertCreated();

    expect(WagerRound::count())->toBe(1)->and($round->wagers()->count())->toBe(2);
});

it('validates the payload', function (array $overrides) {
    placeWager($this, $overrides)
        ->assertStatus(422)
        ->assertJson(['success' => false, 'code' => 'validation_failed', 'retryable' => false])
        ->assertJsonStructure(['message', 'errors']);

    expect(Wager::count())->toBe(0);
})->with([
    'no product' => [['product' => null]],
    'no reference' => [['reference' => null]],
    'no stake' => [['stake' => null]],
    'zero stake' => [['stake' => 0]],
    'negative stake' => [['stake' => -1]],
    'three decimals' => [['stake' => 1.005]],
    'unknown funding' => [['funding' => 'credit']],
    'cash with a free bet amount' => [['free_bet_amount' => 10]],
    'free bet without an amount' => [['funding' => 'free_bet', 'stake' => null]],
    'free bet with a cash stake' => [['funding' => 'free_bet', 'free_bet_amount' => 10]],
    'metadata over 4 KB' => [['metadata' => ['blob' => str_repeat('x', 5000)]]],
    'channel too long' => [['channel' => str_repeat('c', 21)]],
    'stake above what a wallet can hold' => [['stake' => 100000000]],
    'free bet above what a wallet can hold' => [['funding' => 'free_bet', 'stake' => null, 'free_bet_amount' => 100000000]],
]);

it('puts the wagers limiter on the place route', function () {
    $route = app('router')->getRoutes()->match(request()->create('/api/v1/wagers', 'POST'));

    expect($route->gatherMiddleware())->toContain('throttle:wagers')
        ->not->toContain('idempotency')
        ->not->toContain('throttle:write');
});

it('replays a place after its round has closed', function () {
    placeWager($this, ['round_reference' => 'round-1'])->assertCreated();
    WagerRound::where('reference', 'round-1')->update(['status' => WagerRound::STATUS_SETTLED, 'closed_at' => now()]);
    $version = $this->wallet->fresh()->wallet_version;

    placeWager($this, ['round_reference' => 'round-1'])
        ->assertOk()
        ->assertJsonPath('data.replayed', true)
        ->assertJsonPath('data.balance.balance', 200)
        ->assertJsonPath('data.balance.wallet_version', $version);

    expect(LedgerEntry::count())->toBe(2)->and(Wager::count())->toBe(1);
});

it('replays a place after the player starts a break', function () {
    placeWager($this)->assertCreated();
    $this->player->exclusions()->create(['type' => 'break', 'duration' => '24h', 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay()]);

    placeWager($this)->assertOk()->assertJsonPath('data.replayed', true)->assertJsonPath('data.balance.balance', 200);

    expect(LedgerEntry::count())->toBe(2)->and((float) $this->wallet->fresh()->balance)->toBe(200.0);
});

it('replays a place after the product is suspended', function () {
    placeWager($this)->assertCreated();
    $this->product->update(['status' => Product::STATUS_SUSPENDED]);

    placeWager($this)->assertOk()->assertJsonPath('data.replayed', true);

    expect(LedgerEntry::count())->toBe(2)->and((float) $this->wallet->fresh()->balance)->toBe(200.0);
});

it('places once when the first insert loses a race on the unique key', function () {
    $raced = false;
    Wager::creating(function () use (&$raced) {
        if (! $raced) {
            $raced = true;

            throw new UniqueConstraintViolationException('mysql', 'insert into wagers', [], new Exception('Duplicate entry'));
        }
    });

    placeWager($this)->assertCreated()->assertJsonPath('data.replayed', false);

    expect($raced)->toBeTrue()
        ->and(Wager::count())->toBe(1)
        ->and(LedgerEntry::where('entry_type', 'wager_stake')->count())->toBe(2)
        ->and((float) $this->wallet->fresh()->balance)->toBe(200.0);
});

it('answers a spent rate limit with the wager error shape', function () {
    RateLimiter::for('wagers', fn () => Limit::perMinute(2)->by('test'));

    placeWager($this)->assertCreated();
    placeWager($this)->assertOk();

    placeWager($this)
        ->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertExactJson(['success' => false, 'message' => 'Too many requests', 'code' => 'rate_limited', 'retryable' => true]);
});

it('leaves the 429 body of other routes unchanged', function () {
    RateLimiter::for('write', fn () => Limit::perMinute(1)->by('test'));

    $this->postJson('/api/v1/launch-tokens/redeem', [], apiHeaders($this->key->key));
    $response = $this->postJson('/api/v1/launch-tokens/redeem', [], apiHeaders($this->key->key))->assertStatus(429);

    expect($response->json())->not->toHaveKey('code')->not->toHaveKey('retryable');
});

it('answers an unexpected failure with a generic retryable error', function () {
    $this->mock(WagerService::class, fn ($mock) => $mock->shouldReceive('place')->andThrow(new RuntimeException('secret db detail')));

    placeWager($this)
        ->assertStatus(500)
        ->assertExactJson(['success' => false, 'message' => 'Server error', 'code' => 'server_error', 'retryable' => true]);
});
