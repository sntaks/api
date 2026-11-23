<?php

use App\Domain\Jet\JetException;
use App\Models\Customer;
use App\Models\Game;
use App\Models\Wallet;
use App\Services\LaunchTokenService;
use App\Services\PlayerTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    setUpJet($this);
    Game::where('code', 'jet')->update(['launch_url' => 'https://jet.kadi.online/launch']);
    $this->travelTo('2026-11-23 12:00:00');
    $this->player = Customer::factory()->create(['status' => 1]);
    Wallet::forceCreate(['customer_id' => $this->player->id, 'balance' => 1000]);
});

function jetLaunch(object $test, string $launchToken): TestResponse
{
    return $test->post('/jet/launch', ['token' => $launchToken, 'product' => 'jet_classic', 'current_game' => 'jet', 'account_id' => '1']);
}

function jetLaunchToken(Customer $player): string
{
    return app(LaunchTokenService::class)->issue($player, Game::where('code', 'jet')->sole(), '127.0.0.1')['token'];
}

function jetCodeFrom(TestResponse $launch): string
{
    preg_match("/setItem\\('jet\\.code', \"([A-Za-z0-9]{40})\"\\)/", $launch->getContent(), $match);

    return $match[1];
}

function jetBearer(Customer $player): array
{
    return ['Authorization' => 'Bearer '.app(PlayerTokenService::class)->issue($player, Game::where('code', 'jet')->sole())->plainTextToken];
}

it('launches with a one-time code and never puts the player token in the page', function () {
    $launch = jetLaunch($this, jetLaunchToken($this->player))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $code = jetCodeFrom($launch);

    $session = $this->postJson('/api/v1/jet/session', ['code' => $code])->assertOk()
        ->assertJsonPath('player.id', $this->player->id);

    expect($launch->getContent())->not->toContain($session->json('token'))
        ->and(DB::table('jet_session_codes')->value('token_ciphertext'))->toBeNull();

    $this->getJson('/api/v1/jet/me', ['Authorization' => 'Bearer '.$session->json('token')])->assertOk()
        ->assertJsonPath('balance.amount', '1000.00');
});

it('accepts a launch code once and only within 60 seconds', function () {
    $first = jetCodeFrom(jetLaunch($this, jetLaunchToken($this->player)));
    $this->postJson('/api/v1/jet/session', ['code' => $first])->assertOk();

    $this->postJson('/api/v1/jet/session', ['code' => $first])->assertUnauthorized()
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');

    $second = jetCodeFrom(jetLaunch($this, jetLaunchToken($this->player)));
    $this->travel(61)->seconds();

    $this->postJson('/api/v1/jet/session', ['code' => $second])->assertUnauthorized();
});

it('never redeems a launch token twice', function () {
    $launchToken = jetLaunchToken($this->player);
    jetLaunch($this, $launchToken)->assertOk();

    jetLaunch($this, $launchToken)->assertForbidden()->assertSee('kadi-launch-error', false);

    expect(DB::table('jet_session_codes')->count())->toBe(1);
});

it('rejects a player token for another game, and a missing or invalid bearer', function () {
    $otherGame = Game::factory()->create(['code' => 'slots']);
    $otherToken = app(PlayerTokenService::class)->issue($this->player, $otherGame)->plainTextToken;

    $this->getJson('/api/v1/jet/me', ['Authorization' => "Bearer {$otherToken}"])->assertUnauthorized()
        ->assertJsonPath('error.code', 'UNAUTHENTICATED')
        ->assertHeader('X-Request-ID');
    $this->getJson('/api/v1/jet/me')->assertUnauthorized();
    $this->getJson('/api/v1/jet/snapshot', ['Authorization' => 'Bearer nope'])->assertUnauthorized();
    $this->getJson('/api/v1/jet/snapshot')->assertOk()->assertJsonMissingPath('balance');
});

it('places a bet once per operation key and needs the key', function () {
    $round = jetBettingRound();
    $headers = jetBearer($this->player);
    $body = ['round_number' => $round->round_number, 'stake' => '100.00'];

    $this->postJson('/api/v1/jet/bets', $body, $headers)->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
    $this->postJson('/api/v1/jet/bets', ['round_number' => $round->round_number, 'stake' => 100], $headers + ['Idempotency-Key' => 'k1'])->assertUnprocessable();

    $placed = $this->postJson('/api/v1/jet/bets', $body, $headers + ['Idempotency-Key' => 'k1'])->assertCreated();
    $this->postJson('/api/v1/jet/bets', $body, $headers + ['Idempotency-Key' => 'k1'])->assertCreated()
        ->assertJsonPath('operation_id', $placed->json('operation_id'));

    expect(jetBalance($this->player))->toBe('900.00');
    $this->getJson('/api/v1/jet/operations/k1', $headers)->assertOk()->assertJsonPath('bet.id', $placed->json('bet.id'));
});

it('answers 409 ROUND_CLOSED once the betting window has closed, without logging it as an error', function () {
    Exceptions::fake();
    $round = jetBettingRound();
    $this->travelTo($round->betting_closes_at);

    $this->postJson('/api/v1/jet/bets', ['round_number' => $round->round_number, 'stake' => '100.00'], jetBearer($this->player) + ['Idempotency-Key' => 'late'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'ROUND_CLOSED')
        ->assertJsonPath('error.retry', 'reconcile');

    Exceptions::assertNotReported(JetException::class);
});

it('does not let a player cash out another player\'s bet', function () {
    $round = jetBettingRound();
    $bet = jetPlace($this->player, $round, '100.00')['bet'];
    $other = Customer::factory()->create(['status' => 1]);

    $this->postJson("/api/v1/jet/bets/{$bet['id']}/cashout", [], jetBearer($other) + ['Idempotency-Key' => 'steal'])
        ->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
});
