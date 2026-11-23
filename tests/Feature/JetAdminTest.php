<?php

use App\Domain\Jet\RoundScheduler;
use App\Domain\Jet\RoundService;
use App\Models\Customer;
use App\Models\JetRound;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    setUpJet($this);
    $this->travelTo('2026-11-24 12:00:00');
    $this->headers = apiHeaders(createApiKey('gms-jet-key')->key);
    $this->player = Customer::factory()->create(['status' => 1]);
    Wallet::forceCreate(['customer_id' => $this->player->id, 'balance' => 1000]);
});

it('changes the round settings from the next round on, audited, and keeps the house edge read-only', function () {
    $this->putJson('/api/v1/jet/admin/settings', ['house_edge' => 0.01, 'reason' => 'x', 'admin_user' => 'ann'], $this->headers)
        ->assertUnprocessable()->assertJsonPath('code', 'validation_failed');

    $this->putJson('/api/v1/jet/admin/settings', ['betting_window_seconds' => 10, 'max_multiplier' => 1000, 'reason' => 'Faster rounds', 'admin_user' => 'ann'], $this->headers)
        ->assertOk()
        ->assertJsonPath('data.betting_window_seconds', 10)
        ->assertJsonPath('data.max_multiplier', '1000.00')
        ->assertJsonPath('data.house_edge', '0.0300');

    $round = app(RoundService::class)->openBetting(app(RoundService::class)->createRound());

    expect($round->betting_closes_at->equalTo(now()->addSeconds(10)))->toBeTrue()
        ->and($round->max_multiplier_cap)->toBe('1000.00')
        ->and(DB::table('jet_audit_logs')->where('action', 'settings_updated')->where('admin_user', 'ann')->exists())->toBeTrue();
});

it('voids a round in play with a reason, refunding its bets, and refuses one that has crashed or finished', function () {
    $round = jetBettingRound('3.00');
    jetPlace($this->player, $round, '100.00');

    $this->postJson("/api/v1/jet/admin/rounds/{$round->round_number}/void", ['admin_user' => 'ann'], $this->headers)->assertUnprocessable();
    $this->postJson("/api/v1/jet/admin/rounds/{$round->round_number}/void", ['reason' => 'Stuck flight', 'admin_user' => 'ann'], $this->headers)
        ->assertOk()->assertJsonPath('data.status', JetRound::STATUS_VOIDED)->assertJsonPath('data.voided_by', 'gms:ann');

    expect(jetBalance($this->player))->toBe('1000.00')
        ->and(DB::table('jet_audit_logs')->where('action', 'round_voided')->where('round_number', $round->round_number)->exists())->toBeTrue();
    $this->postJson("/api/v1/jet/admin/rounds/{$round->round_number}/void", ['reason' => 'again', 'admin_user' => 'ann'], $this->headers)
        ->assertConflict()->assertJsonPath('code', 'round_finished');

    $crashed = jetBettingRound('2.00');
    $this->travelTo($crashed->betting_closes_at);
    app(RoundService::class)->crashRound(app(RoundService::class)->startRound($crashed));

    $this->postJson("/api/v1/jet/admin/rounds/{$crashed->round_number}/void", ['reason' => 'late', 'admin_user' => 'ann'], $this->headers)
        ->assertConflict()->assertJsonPath('code', 'round_settling');
});

it('keeps a round\'s crash point and seeds hidden from GMS until it has crashed', function () {
    $round = jetBettingRound('3.00');
    jetPlace($this->player, $round, '100.00', '1.50');

    $this->getJson("/api/v1/jet/admin/rounds/{$round->round_number}", $this->headers)->assertOk()
        ->assertJsonPath('data.round.crash_multiplier', null)
        ->assertJsonPath('data.round.server_seed', null)
        ->assertJsonPath('data.totals.bets', 1)
        ->assertJsonPath('data.totals.stakes', '100.00');
    $this->getJson('/api/v1/jet/admin/rounds/999', $this->headers)->assertNotFound()->assertJsonPath('code', 'round_not_found');
});

it('reports the loop alive only with a recent heartbeat', function () {
    $this->getJson('/api/v1/jet/admin/health', $this->headers)->assertOk()
        ->assertJsonPath('data.loop.alive', false)
        ->assertJsonPath('data.healthy', false);

    Cache::put(RoundScheduler::HEARTBEAT_KEY, now()->toIso8601String(), 60);

    $this->getJson('/api/v1/jet/admin/health', $this->headers)->assertOk()->assertJsonPath('data.loop.alive', true);
});
