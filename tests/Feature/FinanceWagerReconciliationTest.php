<?php

use App\Models\ApiKey;
use App\Models\Customer;
use App\Models\Game;
use App\Models\LedgerEntry;
use App\Models\Product;
use App\Models\Wager;
use App\Models\Wallet;
use App\Services\LedgerService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00', config('app.timezone')));
    config(['finance.test_customer_ids' => []]);
    $this->headers = apiHeaders(createApiKey('test-wager-recon-key')->key);

    $houseCustomer = Customer::factory()->create(['id' => 1]);
    $this->house = Wallet::forceCreate(['id' => 1, 'customer_id' => $houseCustomer->id, 'balance' => 10000]);
    $this->player = Customer::factory()->create(['status' => 1]);
    $this->wallet = Wallet::forceCreate(['customer_id' => $this->player->id, 'balance' => 1000]);

    $game = Game::factory()->create(['code' => 'sportsbook']);
    $this->product = Product::factory()->create(['game_id' => $game->id, 'code' => 'sportsbook_main']);
    $this->key = ApiKey::create(['key' => 'game_sportsbook_key', 'name' => 'sportsbook', 'scope' => ApiKey::SCOPE_GAME, 'game_id' => $game->id, 'is_active' => true]);
});

/** An open wager whose cash stake really sits in escrow. */
function reconWager(object $test, string $reference, float $stake = 100, ?Product $product = null): Wager
{
    $wager = Wager::factory()->create([
        'product_id' => ($product ?? $test->product)->id, 'customer_id' => $test->player->id, 'wallet_id' => $test->wallet->id,
        'api_key_id' => $test->key->id, 'reference' => $reference, 'balance' => 0, 'stake' => $stake,
    ]);
    app(LedgerService::class)->recordWagerStake($wager, $test->wallet->fresh(), $stake);

    return $wager->fresh();
}

function wagerChecks(object $test): Collection
{
    Cache::flush();

    return collect($test->getJson('/api/v1/finance/reconciliation', $test->headers)->assertOk()->json('data.checks'))->keyBy('key');
}

it('passes the wager checks on clean wager activity', function () {
    reconWager($this, 'bet-1');

    $checks = wagerChecks($this);

    foreach (['wager_escrow_drift', 'aged_open_wagers', 'settled_wagers_with_balance', 'negative_balances', 'negative_balances_from_reversal', 'negative_house_balance'] as $key) {
        expect($checks[$key]['status'])->toBe('pass', $key);
    }
});

it('flags wager escrow out of step with the ledger', function () {
    reconWager($this, 'bet-1')->update(['balance' => 70]);

    expect(wagerChecks($this)['wager_escrow_drift'])->toMatchArray(['status' => 'fail', 'count' => 1, 'amount' => 30.0]);
});

it('warns about wagers open too long, by product', function () {
    $crash = Product::factory()->create(['game_id' => $this->product->game_id, 'code' => 'sportsbook_live']);
    config(['finance.reconciliation.open_wager_days' => 30, 'finance.reconciliation.open_wager_days_by_product' => ['sportsbook_live' => 1]]);
    reconWager($this, 'old-main')->update(['placed_at' => now()->subDays(31)]);
    reconWager($this, 'young-main')->update(['placed_at' => now()->subDays(5)]);
    reconWager($this, 'old-live', 50, $crash)->update(['placed_at' => now()->subDays(2)]);

    $check = wagerChecks($this)['aged_open_wagers'];

    expect($check)->toMatchArray(['status' => 'warn', 'count' => 2, 'amount' => 150.0])
        ->and(collect($check['samples'])->pluck('reference')->sort()->values()->all())->toBe(['old-live', 'old-main']);
});

it('flags a settled wager that still holds money', function () {
    reconWager($this, 'bet-1')->update(['status' => 'lost']);

    expect(wagerChecks($this)['settled_wagers_with_balance'])->toMatchArray(['status' => 'fail', 'count' => 1, 'amount' => 100.0]);
});

it('lists a player made negative by a reversal apart from other negatives', function () {
    reconWager($this, 'bet-1');
    $this->postJson('/api/v1/wagers/bet-1/settle', ['product' => 'sportsbook_main', 'version' => 1, 'outcome' => 'won', 'payout' => 500], apiHeaders($this->key->key))->assertOk();
    $this->wallet->update(['balance' => 100]);
    $this->postJson('/api/v1/wagers/bet-1/reverse', ['product' => 'sportsbook_main', 'version' => 1, 'reason' => 'Void match'], apiHeaders($this->key->key))->assertOk();

    $other = Customer::factory()->create();
    Wallet::forceCreate(['customer_id' => $other->id, 'balance' => -5]);

    $checks = wagerChecks($this);

    expect((float) $this->wallet->fresh()->balance)->toBeLessThan(0.0)
        ->and($checks['negative_balances_from_reversal'])->toMatchArray(['status' => 'warn', 'count' => 1])
        ->and($checks['negative_balances_from_reversal']['samples'][0]['id'])->toBe($this->wallet->id)
        ->and($checks['negative_balances'])->toMatchArray(['status' => 'fail', 'count' => 1, 'amount' => 5.0]);
});

it('warns about a negative house wallet without failing', function () {
    $this->house->update(['balance' => -250]);

    $checks = wagerChecks($this);

    expect($checks['negative_house_balance'])->toMatchArray(['status' => 'warn', 'count' => 1, 'amount' => 250.0])
        ->and($checks['negative_balances']['status'])->toBe('pass');
});

/** A player made negative by a reversal: won 500, spent down to 100, then the win was reversed. */
function reversalDebt(object $test): float
{
    reconWager($test, 'bet-1');
    $test->postJson('/api/v1/wagers/bet-1/settle', ['product' => 'sportsbook_main', 'version' => 1, 'outcome' => 'won', 'payout' => 500], apiHeaders($test->key->key))->assertOk();
    $test->wallet->update(['balance' => 100]);
    $test->postJson('/api/v1/wagers/bet-1/reverse', ['product' => 'sportsbook_main', 'version' => 1, 'reason' => 'Void match'], apiHeaders($test->key->key))->assertOk();

    return (float) $test->wallet->fresh()->balance;
}

it('keeps a player under the reversal check after a deposit of exactly the debt loses its excise', function () {
    $debt = reversalDebt($this);
    $entry = fn (string $type, float $debit, float $credit, float $before) => LedgerEntry::create([
        'entry_id' => (string) Str::uuid(), 'entry_type' => $type, 'wallet_type' => 'wallet', 'wallet_id' => $this->wallet->id,
        'customer_id' => $this->player->id, 'debit' => $debit, 'credit' => $credit, 'balance_before' => $before, 'balance_after' => $before + $credit - $debit, 'status' => 'settled',
    ]);
    $entry('deposit', 0, -$debt, $debt);
    $entry('excise_duty', 5, 0, 0);
    $this->wallet->update(['balance' => -5]);

    $checks = wagerChecks($this);

    expect($checks['negative_balances_from_reversal'])->toMatchArray(['status' => 'warn', 'count' => 1])
        ->and($checks['negative_balances']['status'])->toBe('pass');
});

it('fails a reversal debt deepened by another debit', function () {
    reversalDebt($this);
    app(LedgerService::class)->recordAdjustment($this->wallet->fresh(), -10, 'manual correction');

    $checks = wagerChecks($this);

    expect($checks['negative_balances'])->toMatchArray(['status' => 'fail', 'count' => 1])
        ->and($checks['negative_balances_from_reversal']['count'])->toBe(0);
});
