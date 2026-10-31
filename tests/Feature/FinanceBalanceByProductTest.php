<?php

use App\Models\Customer;
use App\Models\FinancialSnapshot;
use App\Models\FinancialSnapshotLine;
use App\Models\Game;
use App\Models\GameWallet;
use App\Models\MpesaBalance;
use App\Models\Product;
use App\Models\Wager;
use App\Models\Wallet;
use App\Services\FinanceSnapshotService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00', config('app.timezone')));
    config(['finance.test_customer_ids' => []]);
    $this->headers = apiHeaders(createApiKey('test-balance-by-product-key')->key);

    $house = Customer::factory()->create(['id' => 1]);
    Wallet::forceCreate(['id' => 1, 'customer_id' => $house->id, 'balance' => 5000]);
    $this->player = Customer::factory()->create();
    $this->wallet = Wallet::forceCreate(['customer_id' => $this->player->id, 'balance' => 300]);

    $this->crash = Product::factory()->create(['game_id' => Game::factory()->create(['code' => 'crash'])->id, 'code' => 'crash_classic']);
    $this->wager = Wager::factory()->create([
        'product_id' => $this->crash->id, 'customer_id' => $this->player->id, 'wallet_id' => $this->wallet->id, 'stake' => 100, 'balance' => 100,
    ]);
    GameWallet::create(['game_id' => 'G-OPEN', 'game_type' => 1, 'balance' => 40, 'status' => 1]);
});

function sheet(object $test, string $query = ''): array
{
    Cache::flush();

    return $test->getJson("/api/v1/finance/balance-sheet{$query}", $test->headers)->assertOk()->json('data');
}

it('lists open wagers as a liability', function () {
    $data = sheet($this);

    expect($data['liabilities']['open_wagers'])->toEqual(100)
        ->and($data['liabilities']['total'])->toEqual(440)
        ->and(collect($data['by_product']['products'])->keyBy('product')['crash_classic'])->toMatchArray(['open_wagers' => 100, 'total' => 100])
        ->and(collect($data['by_product']['products'])->keyBy('product')['kadi'])->toMatchArray(['game_escrow' => 40, 'total' => 40])
        ->and($data['by_product']['platform'])->toMatchArray(['customer_wallets' => 300]);
});

it('writes snapshot lines per product', function () {
    app(FinanceSnapshotService::class)->takeSnapshot();
    app(FinanceSnapshotService::class)->takeSnapshot();

    $lines = FinancialSnapshotLine::whereDate('snapshot_date', '2026-10-20')->get();

    expect(FinancialSnapshot::sole()->open_wagers_total)->toBe('100.00')
        ->and($lines->where('account', 'open_wagers')->sole()->product_id)->toBe($this->crash->id)
        ->and((float) $lines->where('account', 'open_wagers')->sole()->amount)->toBe(100.0)
        ->and($lines->where('account', 'game_escrow')->sole()->product_id)->toBe(Product::idFor(Product::CODE_KADI))
        ->and((float) $lines->whereNull('product_id')->where('account', 'customer_wallets')->sole()->amount)->toBe(300.0);
});

it('reads a past balance sheet by product from its snapshot lines', function () {
    app(FinanceSnapshotService::class)->takeSnapshot();
    $this->wager->update(['balance' => 0, 'status' => 'lost']);

    $past = sheet($this, '?as_of=2026-10-20');

    expect($past['liabilities']['open_wagers'])->toEqual(100)
        ->and(collect($past['by_product']['products'])->keyBy('product')['crash_classic']['open_wagers'])->toEqual(100)
        ->and(sheet($this)['liabilities']['open_wagers'])->toEqual(0);
});

it('counts open wagers in cash coverage', function () {
    MpesaBalance::create(['type' => 'c2b', 'account_name' => 'Working Account', 'currency' => 'KES', 'amount' => 420]);
    Cache::flush();

    $check = collect($this->getJson('/api/v1/finance/reconciliation', $this->headers)->assertOk()->json('data.checks'))->firstWhere('key', 'cash_coverage');

    expect($check['samples'][0])->toMatchArray(['cash' => 420, 'owed' => 440])
        ->and($check['status'])->toBe('fail');
});
