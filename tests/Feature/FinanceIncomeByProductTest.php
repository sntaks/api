<?php

use App\Models\ApiKey;
use App\Models\Customer;
use App\Models\Game;
use App\Models\LedgerEntry;
use App\Models\Product;
use App\Models\Wager;
use App\Models\Wallet;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * One day of cross-product activity:
 *  - kadi: a 10.00 house cut;
 *  - sportsbook: a cash win (stake 100, payout 250, 20% tax on the 150 profit = 30) and a cash loss (stake 100);
 *  - crash: a cash void (stake 50) and a free-bet win (payout 40, bonus 10, untaxed);
 *  - sportsbook: a loss that is then reversed (nets to 0).
 */
beforeEach(function () {
    Cache::flush();
    config(['finance.test_customer_ids' => []]);
    $this->headers = apiHeaders(createApiKey('test-income-by-product-key')->key);

    $houseCustomer = Customer::factory()->create(['id' => 1]);
    $this->house = Wallet::forceCreate(['id' => 1, 'customer_id' => $houseCustomer->id, 'balance' => 10000]);
    $this->player = Customer::factory()->create(['status' => 1]);
    $this->wallet = Wallet::forceCreate(['customer_id' => $this->player->id, 'balance' => 1000]);

    $this->sportsbook = gameWithProduct('sportsbook', 'sportsbook_main', 0.2);
    $this->crash = gameWithProduct('crash', 'crash_classic', 0);

    LedgerEntry::create([
        'entry_id' => (string) Str::uuid(), 'entry_type' => 'house_cut', 'wallet_type' => LedgerEntry::WALLET_TYPE_WALLET,
        'wallet_id' => $this->house->id, 'customer_id' => 1, 'product_id' => Product::idFor(Product::CODE_KADI),
        'debit' => 0, 'credit' => 10, 'balance_before' => 10000, 'balance_after' => 10010, 'status' => 'settled',
        'metadata' => ['source' => 'game_drop_payout'],
    ]);
    $this->house->update(['balance' => 10010]);
    $this->houseStart = 10010.0;

    byProductWager($this, $this->sportsbook, 'sb-win', 100);
    byProductSettle($this, $this->sportsbook, 'sb-win', ['outcome' => 'won', 'payout' => 250])->assertJsonPath('data.settlement.tax', 30);
    byProductWager($this, $this->sportsbook, 'sb-loss', 100);
    byProductSettle($this, $this->sportsbook, 'sb-loss', ['outcome' => 'lost', 'payout' => 0]);
    byProductWager($this, $this->crash, 'cr-void', 50);
    byProductSettle($this, $this->crash, 'cr-void', ['outcome' => 'void', 'payout' => 0]);
    byProductWager($this, $this->crash, 'cr-free', 20, freeBet: true);
    byProductSettle($this, $this->crash, 'cr-free', ['outcome' => 'won', 'payout' => 40, 'bonus' => 10]);
    byProductWager($this, $this->sportsbook, 'sb-reversed', 100);
    byProductSettle($this, $this->sportsbook, 'sb-reversed', ['outcome' => 'lost', 'payout' => 0]);
    $this->postJson('/api/v1/wagers/sb-reversed/reverse', ['product' => 'sportsbook_main', 'version' => 1, 'reason' => 'Wrong result'], apiHeaders($this->sportsbook['key']->key))->assertOk();
});

/**
 * @return array{game: Game, product: Product, key: ApiKey}
 */
function gameWithProduct(string $gameCode, string $productCode, float $taxRate): array
{
    $game = Game::factory()->create(['code' => $gameCode]);

    return [
        'game' => $game,
        'product' => Product::factory()->create(['game_id' => $game->id, 'code' => $productCode, 'winnings_tax_rate' => $taxRate]),
        'key' => ApiKey::create(['key' => "game_{$gameCode}_key", 'name' => $gameCode, 'scope' => ApiKey::SCOPE_GAME, 'game_id' => $game->id, 'is_active' => true]),
    ];
}

/** An open wager whose cash stake really sits in escrow. */
function byProductWager(object $test, array $product, string $reference, float $amount, bool $freeBet = false): Wager
{
    $attributes = [
        'product_id' => $product['product']->id, 'customer_id' => $test->player->id, 'wallet_id' => $test->wallet->id,
        'api_key_id' => $product['key']->id, 'reference' => $reference, 'balance' => 0,
    ];

    if ($freeBet) {
        return Wager::factory()->freeBet($amount)->create($attributes);
    }

    $wager = Wager::factory()->create($attributes + ['stake' => $amount]);
    app(LedgerService::class)->recordWagerStake($wager, $test->wallet->fresh(), $amount);

    return $wager;
}

function byProductSettle(object $test, array $product, string $reference, array $payload)
{
    return $test->postJson("/api/v1/wagers/{$reference}/settle", ['product' => $product['product']->code, 'version' => 1] + $payload, apiHeaders($product['key']->key))->assertOk();
}

function incomeStatement(object $test, string $query = ''): array
{
    Cache::flush();

    return $test->getJson("/api/v1/finance/income-statement{$query}", $test->headers)->assertOk()->json('data');
}

it('books house-banked GGR and wager promotions by product', function () {
    $income = incomeStatement($this);
    $rows = collect($income['by_product']['products'])->keyBy('product');

    expect($income['revenue']['house_banked'])->toEqual(-50)
        ->and($income['wager_promotions'])->toEqual(50)
        ->and($income['memo']['winnings_tax_withheld'])->toEqual(30)
        ->and($rows['sportsbook_main'])->toMatchArray([
            'game' => 'sportsbook', 'revenue_model' => 'house_banked', 'revenue' => -50, 'promotions' => 0, 'winnings_tax_withheld' => 30, 'net' => -50,
        ])
        ->and($rows['crash_classic'])->toMatchArray(['revenue' => 0, 'promotions' => 50, 'winnings_tax_withheld' => 0, 'net' => -50])
        ->and($rows['kadi'])->toMatchArray(['game' => 'kadi', 'revenue_model' => 'rake', 'revenue' => 10, 'net' => 10]);
});

it('nets reversed settlements out of GGR', function () {
    $stakeSettled = LedgerEntry::whereIn('entry_type', ['wager_stake_settled', 'wager_stake_settled_reversal'])
        ->where('wallet_id', $this->house->id)->get();

    expect($stakeSettled)->toHaveCount(4)
        ->and(incomeStatement($this)['revenue']['house_banked'])->toEqual(-50);
});

it('adds the products and the platform row up to net income', function () {
    $this->postJson('/api/v1/finance/expenses', [
        'category' => 'hosting', 'amount' => 25, 'expense_date' => now()->toDateString(), 'description' => 'Servers',
    ], $this->headers + ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

    $income = incomeStatement($this);
    $productsNet = collect($income['by_product']['products'])->sum('net');

    expect($income['by_product']['platform'])->toMatchArray(['expenses' => 25, 'net' => -25])
        ->and(round($productsNet + $income['by_product']['platform']['net'], 2))->toEqual($income['net_income'])
        ->and($income['revenue']['total'])->toEqual(-40)
        ->and($income['net_income'])->toEqual(-115);
});

it('matches the house wallet movement', function () {
    $income = incomeStatement($this);
    $houseMovement = (float) $this->house->fresh()->balance - $this->houseStart + 10;

    expect(round($income['revenue']['total'] - $income['revenue']['gift_emoji_sales']['total'] - $income['wager_promotions'] - $income['promotions'], 2))
        ->toEqual(round($houseMovement, 2));
});

it('filters the income statement by product and by game', function () {
    $sportsbook = incomeStatement($this, '?game=sportsbook');

    expect($sportsbook['revenue']['house_banked'])->toEqual(-50)
        ->and($sportsbook['revenue']['games'])->toEqual(0)
        ->and($sportsbook['revenue']['total'])->toEqual(-50)
        ->and($sportsbook['wager_promotions'])->toEqual(0)
        ->and(collect($sportsbook['by_product']['products'])->pluck('product')->all())->toBe(['sportsbook_main'])
        ->and($sportsbook['by_product']['platform']['net'])->toEqual(0)
        ->and($sportsbook['net_income'])->toEqual(-50)
        ->and(incomeStatement($this, '?product=kadi')['revenue']['total'])->toEqual(10);
});

it('exports income by product as CSV', function () {
    $response = $this->get('/api/v1/finance/export/income-by-product', $this->headers)->assertOk();
    $lines = array_map('str_getcsv', array_values(array_filter(explode("\n", trim(ltrim($response->streamedContent(), "\xEF\xBB\xBF"))))));

    expect($lines[0])->toBe(['product', 'game', 'revenue_model', 'revenue', 'promotions', 'winnings_tax_withheld', 'net', 'turnover', 'payouts', 'rtp'])
        ->and(collect($lines)->pluck(0)->all())->toContain('sportsbook_main', 'crash_classic', 'kadi', 'platform');
});

it('shows house-banked GGR and wager promotions in the dashboard summary', function () {
    Cache::flush();
    $today = $this->getJson('/api/v1/finance/summary', $this->headers)->assertOk()->json('data.windows.today');

    expect($today['revenue'])->toMatchArray(['house_banked' => -50, 'total' => -40])
        ->and($today['wager_promotions'])->toEqual(50)
        ->and($today['net_income'])->toEqual(-90);
});
