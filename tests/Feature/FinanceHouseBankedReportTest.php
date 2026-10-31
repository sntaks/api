<?php

use App\Models\ApiKey;
use App\Models\Customer;
use App\Models\Game;
use App\Models\Product;
use App\Models\Wallet;
use App\Services\PlayerTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

/**
 * Two days of instant play by one player:
 *  - 2026-10-20, slots_fruit (untaxed): cash spins lost 20, won 20 -> 75, lost 20, a free spin of 10 that wins 40,
 *    and a lost 20 spin that is reversed (nets to 0);
 *  - 2026-10-21, scratch_50 (20% winnings tax): cards lost 50 x3, one card won 50 -> 100 (tax 20% of 50 = 10).
 */
beforeEach(function () {
    Cache::flush();
    config(['finance.test_customer_ids' => []]);
    $this->headers = apiHeaders(createApiKey('test-house-banked-report-key')->key);

    $houseCustomer = Customer::factory()->create(['id' => 1]);
    Wallet::forceCreate(['id' => 1, 'customer_id' => $houseCustomer->id, 'balance' => 10000]);
    $this->player = Customer::factory()->create(['status' => 1]);
    $this->wallet = Wallet::forceCreate(['customer_id' => $this->player->id, 'balance' => 1000]);

    $this->slots = hbProduct('slots', 'slots_fruit', 0);
    $this->scratch = hbProduct('scratch', 'scratch_50', 0.2);

    $this->travelTo('2026-10-20 10:00:00');
    hbPlay($this, $this->slots, 'spin-1', ['stake' => 20, 'outcome' => 'lost', 'payout' => 0]);
    hbPlay($this, $this->slots, 'spin-2', ['stake' => 20, 'outcome' => 'won', 'payout' => 75]);
    hbPlay($this, $this->slots, 'spin-3', ['stake' => 20, 'outcome' => 'lost', 'payout' => 0]);
    hbPlay($this, $this->slots, 'spin-free', ['funding' => 'free_bet', 'free_bet_amount' => 10, 'outcome' => 'won', 'payout' => 40]);
    hbPlay($this, $this->slots, 'spin-reversed', ['stake' => 20, 'outcome' => 'lost', 'payout' => 0]);
    $this->postJson('/api/v1/wagers/spin-reversed/reverse', ['product' => 'slots_fruit', 'version' => 1, 'reason' => 'Machine fault'], apiHeaders($this->slots['key']->key))->assertOk();

    $this->travelTo('2026-10-21 10:00:00');
    foreach (['card-1', 'card-2', 'card-3'] as $reference) {
        hbPlay($this, $this->scratch, $reference, ['stake' => 50, 'outcome' => 'lost', 'payout' => 0]);
    }
    hbPlay($this, $this->scratch, 'card-4', ['stake' => 50, 'outcome' => 'won', 'payout' => 100]);
});

/**
 * @return array{game: Game, product: Product, key: ApiKey}
 */
function hbProduct(string $gameCode, string $productCode, float $taxRate): array
{
    $game = Game::factory()->create(['code' => $gameCode]);

    return [
        'game' => $game,
        'product' => Product::factory()->create(['game_id' => $game->id, 'code' => $productCode, 'winnings_tax_rate' => $taxRate]),
        'key' => ApiKey::create(['key' => "game_{$gameCode}_key", 'name' => $gameCode, 'scope' => ApiKey::SCOPE_GAME, 'game_id' => $game->id, 'is_active' => true]),
    ];
}

function hbPlay(object $test, array $product, string $reference, array $payload): void
{
    $token = app(PlayerTokenService::class)->issue($test->player, $product['game'])->plainTextToken;

    $test->postJson('/api/v1/wagers/instant', array_merge(['product' => $product['product']->code, 'reference' => $reference, 'funding' => 'cash'], $payload),
        apiHeaders($product['key']->key) + ['Authorization' => 'Bearer '.$token])->assertCreated();
}

function houseBankedReport(object $test, string $query = ''): array
{
    Cache::flush();
    $separator = str_contains($query, '?') ? '&' : '?';

    return $test->getJson("/api/v1/finance/house-banked{$query}{$separator}from=2026-10-20&to=2026-10-21", $test->headers)->assertOk()->json('data');
}

it('reports turnover, payouts, RTP and hold per product', function () {
    $report = houseBankedReport($this);
    $rows = collect($report['by_product'])->keyBy('product');

    expect($rows['slots_fruit'])->toMatchArray([
        'game' => 'slots', 'bets' => 4, 'players' => 1, 'voids' => 0, 'turnover' => 60, 'payouts' => 75, 'ggr' => -15,
        'rtp' => 125, 'hold' => -25, 'bonuses' => 0, 'free_bet_value' => 10, 'free_bet_winnings' => 40, 'winnings_tax_withheld' => 0, 'net' => -55,
    ])
        ->and($rows['scratch_50'])->toMatchArray([
            'game' => 'scratch', 'bets' => 4, 'players' => 1, 'turnover' => 200, 'payouts' => 100, 'ggr' => 100,
            'rtp' => 50, 'hold' => 50, 'free_bet_value' => 0, 'winnings_tax_withheld' => 10, 'net' => 100,
        ])
        ->and($report['totals'])->toMatchArray([
            'bets' => 8, 'players' => 1, 'turnover' => 260, 'payouts' => 175, 'ggr' => 85, 'rtp' => 67.31, 'hold' => 32.69,
            'free_bet_winnings' => 40, 'winnings_tax_withheld' => 10, 'net' => 45,
        ]);
});

it('agrees with the income statement house-banked line', function () {
    $report = houseBankedReport($this);
    Cache::flush();
    $income = $this->getJson('/api/v1/finance/income-statement?from=2026-10-20&to=2026-10-21', $this->headers)->assertOk()->json('data');
    $incomeRows = collect($income['by_product']['products'])->keyBy('product');

    expect($income['revenue']['house_banked'])->toEqual($report['totals']['ggr'])
        ->and($income['wager_promotions'])->toEqual($report['totals']['bonuses'] + $report['totals']['free_bet_winnings'])
        ->and($income['memo']['winnings_tax_withheld'])->toEqual($report['totals']['winnings_tax_withheld']);

    foreach ($report['by_product'] as $row) {
        expect($incomeRows[$row['product']])->toMatchArray([
            'revenue' => $row['ggr'], 'net' => $row['net'], 'turnover' => $row['turnover'], 'payouts' => $row['payouts'], 'rtp' => $row['rtp'],
        ]);
    }
});

it('nets a reversed spin out of every figure', function () {
    $before = houseBankedReport($this, '?product=scratch_50')['totals'];

    $this->postJson('/api/v1/wagers/card-4/reverse', ['product' => 'scratch_50', 'version' => 1, 'reason' => 'Misprinted card'], apiHeaders($this->scratch['key']->key))->assertOk();
    $after = houseBankedReport($this, '?product=scratch_50')['totals'];

    expect($after)->toMatchArray([
        'bets' => $before['bets'] - 1, 'turnover' => 150, 'payouts' => 0, 'ggr' => 150, 'rtp' => 0, 'hold' => 100, 'winnings_tax_withheld' => 0,
    ]);
});

it('filters by game and product and splits by period', function () {
    $byDay = collect(houseBankedReport($this, '?group_by=day')['series'])->keyBy('period');
    $scratch = houseBankedReport($this, '?game=scratch');

    expect($byDay['2026-10-20'])->toMatchArray(['bets' => 4, 'turnover' => 60, 'ggr' => -15])
        ->and($byDay['2026-10-21'])->toMatchArray(['bets' => 4, 'turnover' => 200, 'ggr' => 100, 'rtp' => 50])
        ->and(collect($scratch['by_product'])->pluck('product')->all())->toBe(['scratch_50'])
        ->and($scratch['totals'])->toMatchArray(['turnover' => 200, 'ggr' => 100])
        ->and(houseBankedReport($this, '?product=slots_fruit')['totals'])->toMatchArray(['turnover' => 60, 'free_bet_value' => 10]);
});

it('shows no RTP when nothing was staked', function () {
    $report = houseBankedReport($this, '?product=slots_fruit&group_by=day');

    expect(collect($report['series'])->keyBy('period')['2026-10-21'])->toMatchArray(['turnover' => 0, 'rtp' => null, 'hold' => null]);
});

it('exports the house-banked report as CSV', function () {
    $response = $this->get('/api/v1/finance/export/house-banked?from=2026-10-20&to=2026-10-21&game=slots', $this->headers)->assertOk();
    $lines = array_map('str_getcsv', array_values(array_filter(explode("\n", trim(ltrim($response->streamedContent(), "\xEF\xBB\xBF"))))));

    expect($lines[0])->toBe([
        'product', 'game', 'bets', 'players', 'voids', 'turnover', 'payouts', 'ggr', 'rtp', 'hold',
        'bonuses', 'free_bet_value', 'free_bet_winnings', 'winnings_tax_withheld', 'net',
    ])
        ->and(collect($lines)->pluck(0)->all())->toBe(['product', 'slots_fruit']);
});

it('does not count a tombstone as a void', function () {
    $this->postJson('/api/v1/wagers/card-never-placed/settle', ['product' => 'scratch_50', 'version' => 1, 'outcome' => 'void', 'payout' => 0], apiHeaders($this->scratch['key']->key))->assertCreated();

    expect(houseBankedReport($this, '?product=scratch_50')['totals'])->toMatchArray(['bets' => 4, 'voids' => 0]);
});

it('takes a reversal off in the period it was reversed', function () {
    $this->postJson('/api/v1/wagers/spin-2/reverse', ['product' => 'slots_fruit', 'version' => 1, 'reason' => 'Machine fault'], apiHeaders($this->slots['key']->key))->assertOk();
    $byDay = collect(houseBankedReport($this, '?product=slots_fruit&group_by=day')['series'])->keyBy('period');

    expect($byDay['2026-10-20'])->toMatchArray(['bets' => 4, 'turnover' => 60, 'payouts' => 75])
        ->and($byDay['2026-10-21'])->toMatchArray(['bets' => -1, 'turnover' => -20, 'payouts' => -75, 'ggr' => 55, 'rtp' => null]);
});

it('leaves test customers out, as the income statement does', function () {
    config(['finance.test_customer_ids' => [$this->player->id]]);
    Cache::flush();
    $income = $this->getJson('/api/v1/finance/income-statement?from=2026-10-20&to=2026-10-21', $this->headers)->assertOk()->json('data');

    expect(houseBankedReport($this)['totals'])->toMatchArray(['bets' => 0, 'players' => 0, 'turnover' => 0, 'ggr' => 0])
        ->and($income['revenue']['house_banked'])->toEqual(0)
        ->and(houseBankedReport($this, '?exclude_test=0')['totals'])->toMatchArray(['bets' => 8, 'ggr' => 85]);
});
