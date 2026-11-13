<?php

use App\Models\PredictAuditLog;
use App\Models\PredictMarket;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    setUpPredict($this);
    $this->migration = require database_path('migrations/2026_11_13_174447_add_cut_on_to_predict_markets_table.php');
    // Back to the schema before the change, so up() runs against markets as they were
    $this->migration->down();
});

/**
 * A market on the old default pool terms (cut 10% of the pool, minimum 1.05x).
 */
function oldTermsMarket(object $test, array $attributes = []): PredictMarket
{
    return PredictMarket::factory()->create(['product_id' => $test->product->id, 'cut_bp' => 1000, 'min_multiplier_x100' => 105, ...$attributes]);
}

it('keeps the whole-pool cut on pool markets already resolved, settled or voided', function () {
    $settled = oldTermsMarket($this, ['status' => PredictMarket::STATUS_SETTLED, 'result' => 'yes']);
    $voided = oldTermsMarket($this, ['status' => PredictMarket::STATUS_VOIDED]);

    $this->migration->up();

    expect($settled->fresh()->only(['cut_on', 'cut_bp', 'min_multiplier_x100']))->toBe(['cut_on' => 'pool', 'cut_bp' => 1000, 'min_multiplier_x100' => 105])
        ->and($voided->fresh()->cut_on)->toBe('pool')
        ->and(PredictAuditLog::count())->toBe(0);
});

it('moves live pool markets on the old defaults to the new terms, audited, and flags their odds as changed', function () {
    $open = oldTermsMarket($this, ['status' => PredictMarket::STATUS_OPEN]);
    $this->travel(1)->hours();

    $this->migration->up();

    expect($open->fresh()->only(['cut_on', 'cut_bp', 'min_multiplier_x100']))->toBe(['cut_on' => 'winnings', 'cut_bp' => 2000, 'min_multiplier_x100' => 101])
        ->and($open->fresh()->updated_at->gt($open->updated_at))->toBeTrue()
        ->and(PredictAuditLog::sole()->only(['predict_market_id', 'action', 'admin_user']))->toBe(['predict_market_id' => $open->id, 'action' => 'terms_update', 'admin_user' => 'system']);
});

it('leaves terms an admin set by hand, and LMSR fees, as they are', function () {
    $custom = oldTermsMarket($this, ['status' => PredictMarket::STATUS_OPEN, 'cut_bp' => 1300]);
    $lmsr = oldTermsMarket($this, ['status' => PredictMarket::STATUS_DRAFT, 'engine' => PredictMarket::ENGINE_LMSR]);

    $this->migration->up();

    expect($custom->fresh()->only(['cut_on', 'cut_bp']))->toBe(['cut_on' => 'winnings', 'cut_bp' => 1300])
        ->and($lmsr->fresh()->cut_bp)->toBe(1000)
        ->and(PredictAuditLog::count())->toBe(0);
});
