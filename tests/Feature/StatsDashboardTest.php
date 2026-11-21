<?php

use App\Models\ApiKey;
use App\Models\BetShop;
use App\Models\Customer;
use App\Models\Game;
use App\Models\GameWallet;
use App\Models\Product;
use App\Models\Wager;
use App\Models\Wallet;
use App\Services\PlayerTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Two days of play (all customers registered 2026-10-01, the newcomer on 2026-10-20):
 *  - 2026-10-20, slots_fruit by the player: cash spins lost 20, won 20 -> 75, a free spin of 10 that wins 40,
 *    a void spin of 20, and a lost spin of 20 ("spin-late") that is reversed the next day;
 *  - 2026-10-20, kadi: the player and the newcomer stake 100 each in one game; the player wins and withdraws
 *    (the house keeps its cut);
 *  - 2026-10-20, a test customer loses a 50 spin on slots_fruit;
 *  - 2026-10-21, "spin-late" is reversed; scratch_50 (20% winnings tax) by the player: lost 50, won 50 -> 100.
 * Requests are made on 2026-10-22, so both days are over.
 */
beforeEach(function () {
    Cache::flush();
    config(['stats.on_demand_days' => 10, 'stats.today_refresh_seconds' => 0]);
    $this->headers = apiHeaders(createApiKey('test-stats-dashboard-key')->key);

    $this->travelTo('2026-10-01 09:00:00');
    $houseCustomer = Customer::factory()->create(['id' => 1]);
    Wallet::forceCreate(['id' => 1, 'customer_id' => $houseCustomer->id, 'balance' => 10000]);
    $this->player = Customer::factory()->create(['status' => 1]);
    $this->wallet = Wallet::forceCreate(['customer_id' => $this->player->id, 'balance' => 1000]);
    $this->tester = Customer::factory()->create(['status' => 1]);
    Wallet::forceCreate(['customer_id' => $this->tester->id, 'balance' => 1000]);
    config(['finance.test_customer_ids' => [$this->tester->id]]);

    $this->slots = statsProduct('slots', 'slots_fruit', 0);
    $this->scratch = statsProduct('scratch', 'scratch_50', 0.2);

    $this->travelTo('2026-10-20 10:00:00');
    $this->newcomer = Customer::factory()->create(['status' => 1]);
    Wallet::forceCreate(['customer_id' => $this->newcomer->id, 'balance' => 1000]);

    statsPlay($this, $this->player, $this->slots, 'spin-1', ['stake' => 20, 'outcome' => 'lost', 'payout' => 0]);
    statsPlay($this, $this->player, $this->slots, 'spin-2', ['stake' => 20, 'outcome' => 'won', 'payout' => 75]);
    statsPlay($this, $this->player, $this->slots, 'spin-free', ['funding' => 'free_bet', 'free_bet_amount' => 10, 'outcome' => 'won', 'payout' => 40]);
    statsPlace($this, $this->player, $this->slots, 'spin-void', 20);
    $this->postJson('/api/v1/wagers/spin-void/settle', ['product' => 'slots_fruit', 'version' => 1, 'outcome' => 'void', 'payout' => 0], apiHeaders($this->slots['key']->key))->assertOk();
    statsPlay($this, $this->player, $this->slots, 'spin-late', ['stake' => 20, 'outcome' => 'lost', 'payout' => 0]);
    statsPlay($this, $this->tester, $this->slots, 'spin-test', ['stake' => 50, 'outcome' => 'lost', 'payout' => 0]);

    $game = GameWallet::create(['game_id' => 'G-STATS', 'game_type' => 1, 'balance' => 0, 'status' => 1]);
    foreach ([$this->player, $this->newcomer] as $customer) {
        $this->postJson('/api/v1/game/bets', ['game_wallet_id' => $game->id, 'customer_id' => $customer->id, 'payment_type' => 'deposit', 'amount' => 100], $this->headers)->assertCreated();
    }
    $this->postJson('/api/v1/game/withdraw/'.encryptId($game->id), ['customer_id' => (string) $this->player->id], $this->headers)->assertCreated();

    $this->travelTo('2026-10-21 10:00:00');
    $this->postJson('/api/v1/wagers/spin-late/reverse', ['product' => 'slots_fruit', 'version' => 1, 'reason' => 'Machine fault'], apiHeaders($this->slots['key']->key))->assertOk();
    statsPlay($this, $this->player, $this->scratch, 'card-1', ['stake' => 50, 'outcome' => 'lost', 'payout' => 0]);
    statsPlay($this, $this->player, $this->scratch, 'card-2', ['stake' => 50, 'outcome' => 'won', 'payout' => 100]);

    $this->travelTo('2026-10-22 12:00:00');
});

/**
 * @return array{game: Game, product: Product, key: ApiKey}
 */
function statsProduct(string $gameCode, string $productCode, float $taxRate): array
{
    $game = Game::factory()->create(['code' => $gameCode]);

    return [
        'game' => $game,
        'product' => Product::factory()->create(['game_id' => $game->id, 'code' => $productCode, 'winnings_tax_rate' => $taxRate]),
        'key' => ApiKey::create(['key' => "game_{$gameCode}_key", 'name' => $gameCode, 'scope' => ApiKey::SCOPE_GAME, 'game_id' => $game->id, 'is_active' => true]),
    ];
}

function statsPlay(object $test, Customer $customer, array $product, string $reference, array $payload): void
{
    $token = app(PlayerTokenService::class)->issue($customer, $product['game'])->plainTextToken;

    $test->postJson('/api/v1/wagers/instant', array_merge(['product' => $product['product']->code, 'reference' => $reference, 'funding' => 'cash'], $payload),
        apiHeaders($product['key']->key) + ['Authorization' => 'Bearer '.$token])->assertCreated();
}

function statsPlace(object $test, Customer $customer, array $product, string $reference, float $stake): void
{
    $token = app(PlayerTokenService::class)->issue($customer, $product['game'])->plainTextToken;

    $test->postJson('/api/v1/wagers', ['product' => $product['product']->code, 'reference' => $reference, 'funding' => 'cash', 'stake' => $stake],
        apiHeaders($product['key']->key) + ['Authorization' => 'Bearer '.$token])->assertCreated();
}

function statsGet(object $test, string $path, string $query = ''): array
{
    Cache::flush();

    return $test->getJson("/api/v1/stats/{$path}?{$query}", $test->headers)->assertOk()->json('data');
}

function statsDashboard(object $test, string $query = ''): array
{
    return statsGet($test, 'dashboard', 'from=2026-10-20&to=2026-10-21'.($query === '' ? '' : '&'.$query));
}

it('counts bets, voids, turnover and free bets, and a reversal on the day it was made', function () {
    $dashboard = statsDashboard($this, 'product=slots_fruit');
    $row = collect($dashboard['by_product'])->sole();
    $series = collect($dashboard['series'])->keyBy('date');

    // bets: lost, won, free, late (void apart) on the 20th, the late one taken off on the 21st
    expect($row)->toMatchArray([
        'product' => 'slots_fruit', 'game' => 'slots', 'revenue_model' => 'house_banked',
        'bets' => 3, 'voids' => 1, 'players' => 1, 'turnover' => 40, 'payouts' => 75, 'ggr' => -35,
        'hold' => -87.5, 'rtp' => 187.5, 'free_bet_value' => 10, 'promotions' => 40, 'net' => -75,
    ])
        ->and($series['2026-10-20'])->toMatchArray(['turnover' => 60, 'ggr' => -15])
        ->and($series['2026-10-21'])->toMatchArray(['turnover' => -20, 'ggr' => -20]);
});

it('leaves test customers out unless asked for them', function () {
    $without = collect(statsDashboard($this, 'product=slots_fruit')['by_product'])->sole();
    $with = collect(statsDashboard($this, 'product=slots_fruit&exclude_test=0')['by_product'])->sole();

    expect($with)->toMatchArray(['bets' => $without['bets'] + 1, 'turnover' => $without['turnover'] + 50, 'ggr' => $without['ggr'] + 50, 'players' => 2]);
});

it('reports a rake product by entries, stakes and house cut', function () {
    $kadi = collect(statsDashboard($this, 'game=kadi')['by_product'])->firstWhere('product', 'kadi');
    $cut = (float) DB::table('ledger_entries')->whereIn('entry_type', ['house_cut', 'house_cut_reversal'])->where('product_id', Product::idFor(Product::CODE_KADI))->sum(DB::raw('credit - debit'));

    expect($cut)->toBeGreaterThan(0)
        ->and($kadi)->toMatchArray([
            'revenue_model' => 'rake', 'bets' => 2, 'players' => 2, 'turnover' => 200, 'payouts' => null, 'rtp' => null,
            'ggr' => round($cut, 2), 'hold' => round($cut / 200 * 100, 2), 'net' => round($cut, 2),
        ]);
});

it('equals the income statement and the house-banked report for the same filters', function (string $query) {
    $dashboard = collect(statsDashboard($this, $query)['by_product'])->keyBy('product');
    $range = 'from=2026-10-20&to=2026-10-21'.($query === '' ? '' : '&'.$query);
    Cache::flush();
    $income = collect($this->getJson("/api/v1/finance/income-statement?{$range}", $this->headers)->assertOk()->json('data.by_product.products'))->keyBy('product');
    Cache::flush();
    $houseBanked = collect($this->getJson("/api/v1/finance/house-banked?{$range}", $this->headers)->assertOk()->json('data.by_product'))->keyBy('product');

    expect($dashboard->keys()->sort()->values()->all())->toBe($income->keys()->sort()->values()->all());

    foreach ($dashboard as $code => $row) {
        expect($row['ggr'])->toEqual($income[$code]['revenue'])
            ->and($row['net'])->toEqual($income[$code]['net'])
            ->and($row['promotions'])->toEqual($income[$code]['promotions'])
            ->and($row['winnings_tax_withheld'])->toEqual($income[$code]['winnings_tax_withheld']);

        if ($row['revenue_model'] === 'house_banked') {
            expect($row)->toMatchArray(array_intersect_key($houseBanked[$code], array_flip(['bets', 'voids', 'turnover', 'payouts', 'ggr', 'rtp', 'hold', 'free_bet_value'])));
        }
    }
})->with([
    'all products' => '',
    'one game' => 'game=scratch',
    'one product' => 'product=slots_fruit',
    'a rake product' => 'product=kadi',
    'test customers included' => 'exclude_test=0',
]);

it('adds up totals, players, cash and the comparison window', function () {
    $dashboard = statsDashboard($this);
    $previous = statsGet($this, 'dashboard', 'from=2026-10-21&to=2026-10-21');

    expect($dashboard['range'])->toBe(['from' => '2026-10-20', 'to' => '2026-10-21', 'timezone' => 'Africa/Nairobi'])
        ->and($dashboard['compare'])->toMatchArray(['mode' => 'previous_period', 'from' => '2026-10-18', 'to' => '2026-10-19'])
        ->and($dashboard['totals'])->toMatchArray([
            'turnover' => round(collect($dashboard['by_product'])->sum('turnover'), 2),
            'ggr' => round(collect($dashboard['by_product'])->sum('ggr'), 2),
            'active_players' => 2, 'new_players' => 1,
        ])
        ->and($dashboard['players'])->toMatchArray([
            'new' => 1, 'returning' => 1, 'cross_game' => ['1' => 1, '2' => 0, '3_plus' => 1],
        ])
        ->and($dashboard['players']['active'])->toBe(['day' => 1, 'week' => 2, 'month' => 2])
        ->and(collect($dashboard['by_game'])->firstWhere('game', 'kadi'))->toMatchArray(['products' => ['kadi', 'kadi_jackpot', 'kadi_tournament'], 'players' => 2])
        // the 21st alone, compared with the 20th
        ->and($previous['totals']['previous']['turnover'])->toEqual(collect(statsDashboard($this)['series'])->firstWhere('date', '2026-10-20')['turnover'])
        ->and($previous['totals']['delta_pct']['active_players'])->toEqual(-50);
});

it('gives the same figures after a rebuild, and a rebuild twice is a rebuild once', function () {
    $live = statsDashboard($this);
    $volatile = ['generated_at' => true, 'warnings' => true];
    $rows = fn () => collect(['stats_daily_product', 'stats_daily_player_product', 'stats_daily_platform', 'stats_hourly_activity'])
        ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->orderByRaw('1, 2, 3')->get()->map(fn ($row) => (array) $row)->all()])->all();

    $this->artisan('stats:rebuild', ['--from' => '2026-10-20', '--to' => '2026-10-21'])->assertSuccessful();
    $once = $rows();
    $this->artisan('stats:rebuild', ['--from' => '2026-10-20', '--to' => '2026-10-21'])->assertSuccessful();

    expect($rows())->toBe($once)
        ->and(array_diff_key(statsDashboard($this), $volatile))->toBe(array_diff_key($live, $volatile));
});

it('reads today live, after the last change', function () {
    $this->travelTo('2026-10-21 18:00:00');
    $before = collect(statsGet($this, 'dashboard', 'period=today&product=scratch_50')['by_product'])->sole();

    statsPlay($this, $this->player, $this->scratch, 'card-3', ['stake' => 50, 'outcome' => 'lost', 'payout' => 0]);
    $after = statsGet($this, 'dashboard', 'period=today&product=scratch_50');

    expect(collect($after['by_product'])->sole()['turnover'])->toEqual($before['turnover'] + 50)
        ->and($after['range']['from'])->toBe('2026-10-21')
        ->and($after['generated_at'])->toBe(now()->toIso8601String());
});

it('warns about days it could not build in time', function () {
    config(['stats.on_demand_days' => 0]);

    $dashboard = statsDashboard($this);

    expect($dashboard['warnings'][0])->toContain('have no complete rollup yet and count as zero')->toContain('stats:rebuild')
        ->and($dashboard['totals']['turnover'])->toEqual(0);
});

it('splits each product into a series and an hourly heatmap', function () {
    $games = statsGet($this, 'games', 'from=2026-10-20&to=2026-10-21&game=scratch');
    $row = collect($games['by_product'])->sole();
    $cell = collect($games['hourly_heatmap'])->first(fn ($cell) => $cell['weekday'] === 'wed' && $cell['hour'] === 10);

    expect($row)->toMatchArray(['product' => 'scratch_50', 'bets' => 2, 'turnover' => 100, 'payouts' => 100, 'ggr' => 0, 'winnings_tax_withheld' => 10])
        ->and(collect($row['series'])->keyBy('date')['2026-10-21'])->toMatchArray(['bets' => 2, 'players' => 1, 'turnover' => 100, 'rtp' => 100])
        ->and($games['hourly_heatmap'])->toHaveCount(168)
        ->and($cell)->toMatchArray(['stakes' => 100, 'players' => 1, 'avg_players' => 1]);
});

it('shows open wagers and their potential payout', function () {
    $open = Wager::factory()->create([
        'product_id' => $this->slots['product']->id, 'customer_id' => $this->player->id, 'wallet_id' => $this->wallet->id,
        'api_key_id' => $this->slots['key']->id, 'reference' => 'open-1', 'stake' => 30, 'metadata' => ['potential_payout' => 900],
    ]);

    $liability = statsGet($this, 'liability', 'product=slots_fruit');

    // spin-late is open again since its settlement was reversed
    expect($open->status)->toBe(Wager::STATUS_OPEN)
        ->and($liability)->toMatchArray(['open_wagers' => 2, 'open_stakes' => 50, 'open_potential_payout' => 900])
        ->and($liability['by_product'][0])->toMatchArray(['wagers_with_potential_payout' => 1, 'potential_payout_basis' => 'reported'])
        ->and($liability['top_positions'][0])->toMatchArray(['reference' => 'open-1', 'product' => 'slots_fruit', 'potential_payout' => 900])
        ->and($liability['top_positions'][0])->not->toHaveKey('customer_id')
        ->and(collect(statsDashboard($this)['by_product'])->firstWhere('product', 'slots_fruit'))->toMatchArray(['open_wagers' => 2, 'open_stakes' => 50]);
});

it('validates the query', function (string $query, string $field) {
    Cache::flush();
    $this->getJson("/api/v1/stats/dashboard?{$query}", $this->headers)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'period with dates' => ['period=7d&from=2026-10-01', 'period'],
    'unknown period' => ['period=fortnight', 'period'],
    'unknown compare' => ['compare=last_week', 'compare'],
    'unknown product' => ['product=nope', 'product'],
    'product of another game' => ['product=slots_fruit&game=scratch', 'product'],
    'range too long' => ['from=2025-01-01&to=2026-10-21', 'to'],
]);

it('compares with the same period last year', function () {
    expect(statsDashboard($this, 'compare=same_period_last_year')['compare'])
        ->toMatchArray(['mode' => 'same_period_last_year', 'from' => '2025-10-20', 'to' => '2025-10-21']);
});

it('answers full keys only', function () {
    $site = ApiKey::create(['key' => 'stats-site-key', 'name' => 'kadi', 'scope' => ApiKey::SCOPE_SITE, 'is_active' => true]);
    $shop = createShopKey(BetShop::factory()->create());

    foreach (['dashboard', 'games', 'liability'] as $path) {
        foreach ([$site->key, $this->slots['key']->key, $shop->key] as $key) {
            $this->getJson("/api/v1/stats/{$path}", apiHeaders($key))->assertForbidden();
        }
    }
});

it('has its own rate limit, apart from the finance reports', function () {
    config(['stats.rate_limit_per_minute' => 2]);

    $this->getJson('/api/v1/stats/liability', $this->headers)->assertOk();
    $this->getJson('/api/v1/stats/liability', $this->headers)->assertOk();
    $this->getJson('/api/v1/stats/liability', $this->headers)->assertTooManyRequests();
    $this->getJson('/api/v1/finance/summary', $this->headers)->assertOk();
});

it('refuses a bad rebuild range', function () {
    $this->artisan('stats:rebuild', ['--from' => '2026-10-21', '--to' => '2026-10-20'])->assertFailed();
    $this->artisan('stats:rebuild', ['--from' => 'not-a-date'])->assertFailed();
});

it('counts players per day, per game and per week', function () {
    $all = collect(statsDashboard($this)['series'])->keyBy('date');
    $kadi = statsDashboard($this, 'game=kadi');
    $slots = collect(statsDashboard($this, 'product=slots_fruit&exclude_test=0')['series'])->keyBy('date');
    $weeks = statsDashboard($this, 'group_by=week');

    expect($all['2026-10-20']['active_players'])->toBe(2)
        ->and($all['2026-10-21']['active_players'])->toBe(1)
        ->and(collect($kadi['series'])->keyBy('date')['2026-10-20']['active_players'])->toBe(2)
        ->and(collect(collect($kadi['by_game'])->sole()['series'])->keyBy('date')['2026-10-20']['players'])->toBe(2)
        ->and($slots['2026-10-20']['active_players'])->toBe(2)
        ->and($weeks['series'])->toBe([['date' => '2026-10-19'] + collect($weeks['series'])->first()])
        ->and($weeks['series'][0])->toMatchArray(['active_players' => 2, 'ggr' => $weeks['totals']['ggr']]);
});
