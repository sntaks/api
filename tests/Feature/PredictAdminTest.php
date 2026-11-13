<?php

use App\Models\ApiKey;
use App\Models\PredictAuditLog;
use App\Models\PredictMarket;
use App\Models\WagerRound;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    setUpPredict($this);
});

function customMarketPayload(array $overrides = []): array
{
    return array_merge([
        'admin_user' => 'ops@kadi',
        'question' => 'Will Harambee Stars qualify for AFCON 2027?',
        'description' => 'Kenya\'s senior men\'s team.',
        'resolution_criteria' => 'YES if CAF lists Kenya among the qualified teams by the deadline.',
        'resolution_source' => 'https://www.cafonline.com',
        'category' => 'sport',
        'countries' => ['KE'],
        'close_at' => now()->addDays(10)->toIso8601String(),
        'resolution_deadline_at' => now()->addDays(12)->toIso8601String(),
        'start_probability' => 0.3,
    ], $overrides);
}

it('creates a custom market as a draft with the configured defaults and logs it', function () {
    $response = $this->postJson('/api/v1/predict/admin/markets', customMarketPayload(), $this->adminHeaders);

    $response->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.cut', 0.2)
        ->assertJsonPath('data.seed', 5000)
        ->assertJsonPath('data.min_stake', 10)
        ->assertJsonPath('data.max_stake', 5000)
        ->assertJsonPath('data.max_payout', 250000)
        ->assertJsonPath('data.min_multiplier', 1.01)
        ->assertJsonPath('data.product', 'predict_pool');

    $log = PredictAuditLog::sole();
    expect($log->only(['action', 'admin_user', 'predict_market_id']))->toBe(['action' => 'create', 'admin_user' => 'ops@kadi', 'predict_market_id' => $response->json('data.id')])
        ->and($log->api_key_id)->toBe(ApiKey::where('key', 'gms_full_key')->value('id'));
});

it('rejects invalid custom market terms', function (array $overrides, string $field, string $message) {
    $this->postJson('/api/v1/predict/admin/markets', customMarketPayload($overrides), $this->adminHeaders)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field => $message]);
})->with([
    'deadline before close' => [['resolution_deadline_at' => now()->addDays(5)->toIso8601String()], 'resolution_deadline_at', 'The resolution deadline at field must be a date after or equal to close at.'],
    'close in the past' => [['close_at' => now()->subHour()->toIso8601String()], 'close_at', 'The close at field must be a date after now.'],
    'probability too high' => [['start_probability' => 0.995], 'start_probability', 'The start probability field must be between 0.01 and 0.99.'],
    'no resolution criteria' => [['resolution_criteria' => ''], 'resolution_criteria', 'The resolution criteria field is required.'],
    'category not licensed' => [['category' => 'crypto'], 'category', 'The selected category is invalid.'],
    'favourite opens below the minimum' => [['start_probability' => 0.9, 'min_multiplier' => 1.1], 'start_probability', 'With this cut, the favourite would open below the minimum multiplier.'],
    'min stake above max' => [['min_stake' => 100, 'max_stake' => 50], 'max_stake', 'The max stake must be at least the min stake.'],
]);

it('publishes a draft: seeds the pool and opens the wager round', function () {
    $market = PredictMarket::factory()->create(['product_id' => $this->product->id, 'seed_minor' => 500000, 'start_probability_bp' => 3000]);

    $this->postJson("/api/v1/predict/admin/markets/{$market->id}/publish", ['admin_user' => 'ops@kadi'], $this->adminHeaders)
        ->assertOk()
        ->assertJsonPath('data.market.status', 'open')
        ->assertJsonPath('data.market.pool.seed_yes', 1500)
        ->assertJsonPath('data.market.pool.seed_no', 3500)
        // 1 + 3,500 × 0.8 / 1,500 = 2.866…
        ->assertJsonPath('data.market.odds.yes_multiplier', 2.86);

    expect(WagerRound::where('reference', $market->round_reference)->value('status'))->toBe(WagerRound::STATUS_OPEN)
        ->and(PredictAuditLog::where('action', 'publish')->value('admin_user'))->toBe('ops@kadi');
});

it('refuses a transition from the wrong state', function () {
    $market = PredictMarket::factory()->create(['product_id' => $this->product->id]);

    $this->postJson("/api/v1/predict/admin/markets/{$market->id}/resolve", ['admin_user' => 'ops', 'result' => 'yes'], $this->adminHeaders)
        ->assertConflict()
        ->assertJsonPath('code', 'invalid_transition');

    expect(PredictAuditLog::where('action', 'resolve')->exists())->toBeFalse();
});

it('moves a market through suspend, resume and close', function () {
    $market = PredictMarket::factory()->open()->create(['product_id' => $this->product->id]);

    foreach (['suspend' => 'suspended', 'resume' => 'open', 'close' => 'closed'] as $action => $status) {
        $this->postJson("/api/v1/predict/admin/markets/{$market->id}/{$action}", ['admin_user' => 'ops'], $this->adminHeaders)
            ->assertOk()
            ->assertJsonPath('data.market.status', $status);
    }

    expect(PredictAuditLog::pluck('action')->all())->toBe(['suspend', 'resume', 'close']);
});

it('freezes the pool terms once a market is published', function () {
    $market = PredictMarket::factory()->open()->create(['product_id' => $this->product->id]);

    $this->putJson("/api/v1/predict/admin/markets/{$market->id}", ['admin_user' => 'ops', 'cut' => 0.2], $this->adminHeaders)
        ->assertConflict()
        ->assertJsonPath('code', 'market_terms_frozen');

    $this->putJson("/api/v1/predict/admin/markets/{$market->id}", ['admin_user' => 'ops', 'question' => 'Edited?'], $this->adminHeaders)
        ->assertOk()
        ->assertJsonPath('data.market.question', 'Edited?');
});

it('requires a reason to void and the result to resolve', function () {
    $market = PredictMarket::factory()->open()->create(['product_id' => $this->product->id]);

    $this->postJson("/api/v1/predict/admin/markets/{$market->id}/void", ['admin_user' => 'ops'], $this->adminHeaders)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['reason']);

    $this->postJson("/api/v1/predict/admin/markets/{$market->id}/resolve", ['admin_user' => 'ops'], $this->adminHeaders)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['result']);
});

it('blocks and unblocks a customer on a market, logging both', function () {
    $market = PredictMarket::factory()->open()->create(['product_id' => $this->product->id]);
    $player = predictPlayer();

    $this->postJson("/api/v1/predict/admin/markets/{$market->id}/blocks", ['admin_user' => 'ops', 'customer_id' => $player->id, 'reason' => 'Club official'], $this->adminHeaders)
        ->assertCreated();
    $this->deleteJson("/api/v1/predict/admin/markets/{$market->id}/blocks/{$player->id}", ['admin_user' => 'ops'], $this->adminHeaders)
        ->assertOk();

    expect(PredictAuditLog::pluck('action')->all())->toBe(['block', 'unblock']);
});

it('closes markets whose close time passed on schedule', function () {
    $due = PredictMarket::factory()->open()->create(['product_id' => $this->product->id, 'close_at' => now()->subMinute()]);
    $later = PredictMarket::factory()->open()->create(['product_id' => $this->product->id]);

    $this->artisan('predict:close-due')->assertSuccessful();

    expect($due->fresh()->status)->toBe(PredictMarket::STATUS_CLOSED)
        ->and($later->fresh()->status)->toBe(PredictMarket::STATUS_OPEN)
        ->and(PredictAuditLog::where('action', 'close')->value('admin_user'))->toBe('system');
});

it('keeps the audit log append-only', function () {
    $log = PredictAuditLog::create(['action' => 'create', 'admin_user' => 'ops']);

    expect(fn () => $log->update(['admin_user' => 'someone']))->toThrow(LogicException::class);
});

it('reports house P&L for settled and live markets', function () {
    $settle = function (array $terms): PredictMarket {
        $market = PredictMarket::factory()->open()->create(['product_id' => $this->product->id, 'seed_minor' => 100000, 'start_probability_bp' => 3000, ...$terms]);
        placePredictBet($this, predictPlayer(), $market->id, 'yes', 100)->assertCreated();
        placePredictBet($this, predictPlayer(), $market->id, 'no', 500)->assertCreated();
        placePredictBet($this, predictPlayer(), $market->id, 'yes', 200)->assertCreated();
        $this->postJson("/api/v1/predict/admin/markets/{$market->id}/close", ['admin_user' => 'ops'], $this->adminHeaders)->assertOk();
        $this->postJson("/api/v1/predict/admin/markets/{$market->id}/resolve", ['admin_user' => 'ops', 'result' => 'yes'], $this->adminHeaders)->assertOk();

        return $market;
    };
    $settled = $settle(['cut_bp' => 2000]);
    $oldRule = $settle(['cut_bp' => 1300, 'cut_on' => PredictMarket::CUT_ON_POOL]);

    $lines = collect($this->getJson('/api/v1/predict/admin/reports/markets', $this->adminHeaders)->assertOk()->json('data.items'));

    // YES 600 wins at 2.60x; the cut is 20% of the losing NO side (1,200)
    expect($lines->firstWhere('market_id', $settled->id))->toMatchArray(['stakes' => 800, 'payouts' => 780, 'house_net' => 20, 'cut' => 240, 'seed_result' => -220])
        // The old rule: 13% of the whole pool (1,800)
        ->and($lines->firstWhere('market_id', $oldRule->id))->toMatchArray(['stakes' => 800, 'payouts' => 783, 'house_net' => 17, 'cut' => 234, 'seed_result' => -217]);
});

it('keeps the admin routes away from the Kadi site key', function () {
    $this->getJson('/api/v1/predict/admin/markets', $this->siteHeaders)
        ->assertForbidden()
        ->assertJsonPath('code', 'route_not_allowed');
});
