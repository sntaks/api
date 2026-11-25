<?php

use App\Domain\Jet\RoundService;
use App\Models\Customer;
use App\Models\JetRound;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    setUpJet($this);
    $this->travelTo('2026-11-25 12:00:00');
});

function runningJetRound(string $crashMultiplier): JetRound
{
    $round = jetBettingRound($crashMultiplier);
    test()->travelTo($round->betting_closes_at);

    return app(RoundService::class)->startRound($round);
}

function jetRoundReads(callable $requests): int
{
    $reads = 0;
    DB::listen(function (QueryExecuted $query) use (&$reads) {
        if (str_starts_with($query->sql, 'select') && str_contains($query->sql, 'jet_rounds')) {
            $reads++;
        }
    });
    $requests();

    return $reads;
}

it('serves several players on one IP at client rate without throttling them', function (string $path, int $players, int $perMinute) {
    $tokens = Customer::factory()->count($players)->create(['status' => 1])->map(fn (Customer $player) => jetBearer($player));

    foreach (range(1, $perMinute) as $poll) {
        foreach ($tokens as $bearer) {
            $this->getJson($path, $bearer)->assertOk();
        }
    }
})->with([
    // above the old 600/min per-IP presentation limit
    'presentation, 3 players at 240/min' => ['/api/v1/jet/presentation', 3, 240],
    // above the old 120/min per-IP read limit
    'snapshot, 10 players at 20/min' => ['/api/v1/jet/snapshot', 10, 20],
]);

it('throttles one anonymous IP above the presentation ceiling, and only that IP', function () {
    config(['jet.rate_limits.presentation_per_ip' => 3]);

    foreach (range(1, 3) as $poll) {
        $this->getJson('/api/v1/jet/presentation')->assertOk();
    }

    $this->getJson('/api/v1/jet/presentation')->assertTooManyRequests();
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])->getJson('/api/v1/jet/presentation')->assertOk();
});

it('never gives a forged bearer its own bucket', function (string $path, string $limit) {
    config(["jet.rate_limits.{$limit}" => 2]);

    $this->getJson($path)->assertOk();
    $this->getJson($path)->assertOk();

    $this->getJson($path, ['Authorization' => 'Bearer forged-'.uniqid()])->assertTooManyRequests();
})->with([
    'presentation' => ['/api/v1/jet/presentation', 'presentation_per_ip'],
    'public reads' => ['/api/v1/jet/rounds', 'reads_per_ip'],
]);

it('never shows a multiplier at or past the crash point from a cached running row', function () {
    config(['jet.presentation_cache_ms' => 60000]);
    $round = runningJetRound('2.00');

    $this->travel(1)->seconds();
    $this->getJson('/api/v1/jet/presentation')->assertOk()->assertJsonPath('status', 'running')
        ->assertJsonPath('multiplier', '1.28');

    // e^(0.25·t) reaches 2.00 at t ≈ 2.77 s; the cached row still says running, the worker is late
    $this->travel(2)->seconds();
    $this->getJson('/api/v1/jet/presentation')->assertOk()->assertJsonPath('status', 'running')
        ->assertJsonPath('multiplier', null);

    app(RoundService::class)->crashRound($round);
    $this->getJson('/api/v1/jet/presentation')->assertOk()->assertJsonPath('status', 'crashed')
        ->assertJsonPath('multiplier', null);
});

it('reads the round once per cache window however many clients poll, and again after a round save', function () {
    $round = runningJetRound('50.00');

    $reads = jetRoundReads(function () {
        foreach (range(1, 20) as $poll) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.1.{$poll}"])->getJson('/api/v1/jet/presentation')->assertOk();
            $this->travel(10)->milliseconds();
        }
    });
    expect($reads)->toBe(1);

    $reads = jetRoundReads(function () use ($round) {
        app(RoundService::class)->crashRound($round);
        $this->getJson('/api/v1/jet/presentation')->assertJsonPath('status', 'crashed');
    });
    expect($reads)->toBe(2);
});
