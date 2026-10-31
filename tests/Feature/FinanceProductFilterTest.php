<?php

use App\Models\Customer;
use App\Models\Game;
use App\Models\LedgerEntry;
use App\Models\Product;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    config(['finance.test_customer_ids' => []]);
    $this->headers = apiHeaders(createApiKey('test-product-filter-key')->key);

    $this->crash = Game::factory()->create(['code' => 'crash']);
    $this->classic = Product::factory()->create(['game_id' => $this->crash->id, 'code' => 'crash_classic']);
    $this->turbo = Product::factory()->create(['game_id' => $this->crash->id, 'code' => 'crash_turbo']);
    $this->kadi = Product::where('code', Product::CODE_KADI)->sole();

    $this->player = Customer::factory()->create(['name' => 'Jane Player']);
    $this->wallet = Wallet::forceCreate(['customer_id' => $this->player->id, 'balance' => 1000]);

    productEntry($this, null, 'deposit', 0, 100);
    productEntry($this, $this->kadi->id, 'game_bet', 30, 0);
    productEntry($this, $this->classic->id, 'wager_stake', 50, 0);
    productEntry($this, $this->turbo->id, 'wager_stake', 20, 0);
});

/** A settled entry on the player's wallet, tagged with a product (null = platform). */
function productEntry(object $test, ?int $productId, string $type, float $debit, float $credit): LedgerEntry
{
    return LedgerEntry::create([
        'entry_id' => (string) Str::uuid(),
        'entry_type' => $type,
        'wallet_type' => LedgerEntry::WALLET_TYPE_WALLET,
        'wallet_id' => $test->wallet->id,
        'customer_id' => $test->player->id,
        'product_id' => $productId,
        'debit' => $debit,
        'credit' => $credit,
        'balance_before' => 0,
        'balance_after' => $credit - $debit,
        'status' => 'settled',
    ]);
}

function filtered(object $test, string $path)
{
    Cache::flush();

    return $test->getJson("/api/v1/finance/{$path}", $test->headers);
}

it('filters the ledger by product and by game', function () {
    expect(filtered($this, 'ledger')->json('data.pagination.total'))->toBe(4)
        ->and(filtered($this, 'ledger?product=crash_classic')->json('data.items.*.entry_type'))->toBe(['wager_stake'])
        ->and(filtered($this, 'ledger?game=crash')->json('data.pagination.total'))->toBe(2)
        ->and(filtered($this, 'ledger?product=kadi')->json('data.items.*.entry_type'))->toBe(['game_bet']);
});

it('filters top customers by product', function () {
    $all = filtered($this, 'customers/top')->assertOk()->json('data.items.0');
    $classic = filtered($this, 'customers/top?product=crash_classic')->assertOk()->json('data.items.0');

    expect($all)->toMatchArray(['deposited' => 100, 'staked' => 100])
        ->and($classic)->toMatchArray(['deposited' => 0, 'staked' => 50]);
});

it('reports a trial balance slice for one product', function () {
    $slice = filtered($this, 'trial-balance?game=crash')->assertOk()->json('data');

    expect(collect($slice['lines'])->pluck('entry_type')->unique()->values()->all())->toBe(['wager_stake'])
        ->and($slice['totals'])->toEqual(['debit' => 70, 'credit' => 0])
        ->and($slice['check']['balanced'])->toBeNull()
        ->and(filtered($this, 'trial-balance')->json('data.check.balanced'))->toBeBool();
});

it('filters a CSV export of the ledger by product', function () {
    $response = $this->get('/api/v1/finance/export/ledger?product=crash_turbo', $this->headers)->assertOk();
    $lines = array_values(array_filter(explode("\n", trim(ltrim($response->streamedContent(), "\xEF\xBB\xBF")))));

    expect($lines)->toHaveCount(2)
        ->and($lines[1])->toContain('wager_stake');
});

it('refuses a product filter on cash reports', function (string $path) {
    filtered($this, $path)
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('code', 'product_filter_not_supported');
})->with([
    'cash flow' => ['cash-flow?product=kadi'],
    'deposits' => ['deposits?game=crash'],
    'excise duty' => ['excise-duty?product=kadi'],
    'summary' => ['summary?product=kadi'],
    'balance sheet' => ['balance-sheet?product=kadi'],
    'deposits export' => ['export/deposits?product=kadi'],
]);

it('refuses a product filter on a customer statement', function () {
    $this->getJson('/api/v1/finance/customers/'.encryptId($this->player->id).'/statement?product=kadi', $this->headers)
        ->assertStatus(422)
        ->assertJsonPath('code', 'product_filter_not_supported');
});

it('rejects an unknown product or game, or a product of another game', function (string $query, string $field) {
    filtered($this, "ledger?{$query}")->assertStatus(422)->assertJsonValidationErrors($field);
})->with([
    'unknown product' => ['product=nope', 'product'],
    'unknown game' => ['game=nope', 'game'],
    'product of another game' => ['product=kadi&game=crash', 'product'],
]);
