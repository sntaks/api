<?php

use App\Models\CompetitionTransaction;
use App\Models\CompetitionWallet;
use App\Models\Complaint;
use App\Models\Customer;
use App\Models\GameTransaction;
use App\Models\GameWallet;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse('2026-10-16 12:00:00', config('app.timezone')));
    config([
        'finance.test_customer_ids' => [2],
        'finance.open_wallets.game_idle_minutes' => 30,
        'finance.open_wallets.competition_idle_minutes' => 1440,
    ]);

    $this->headers = apiHeaders(createApiKey('test-open-wallets-key')->key);

    Customer::factory()->create(['id' => 2, 'name' => 'Test Player']);
    $this->alice = Customer::factory()->create(['id' => 900, 'name' => 'Alice Wanjiru']);
    $this->bob = Customer::factory()->create(['id' => 901, 'name' => 'Bob Otieno']);
    $this->carol = Customer::factory()->create(['id' => 902, 'name' => 'Carol Akinyi']);
});

/**
 * A game wallet holding its players' stakes, the last one made $idleMinutes ago.
 *
 * @param  array<int, float>  $stakes  customer id (null for a deleted customer) => amount, in order
 */
function openGame(string $gameId, array $stakes, int $idleMinutes, int $status = 1): GameWallet
{
    $lastStakeAt = now()->subMinutes($idleMinutes);
    $game = GameWallet::forceCreate([
        'game_id' => $gameId, 'game_type' => 1, 'balance' => array_sum(array_column($stakes, 1)), 'status' => $status,
        'created_at' => $lastStakeAt->copy()->subMinutes(count($stakes)), 'updated_at' => $lastStakeAt,
    ]);

    foreach (array_values($stakes) as $index => [$customerId, $amount]) {
        $at = $lastStakeAt->copy()->subMinutes(count($stakes) - 1 - $index);
        GameTransaction::forceCreate([
            'game_wallet_id' => $game->id, 'customer_id' => $customerId, 'payment_type' => 'deposit', 'amount' => $amount, 'status' => 2,
            'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    return $game;
}

/**
 * A competition wallet whose last entry or round was $idleMinutes ago.
 */
function openCompetition(Customer $player, int $gameType, int $rounds, float $balance, int $idleMinutes, int $status = 1): CompetitionWallet
{
    $at = now()->subMinutes($idleMinutes);
    $wallet = CompetitionWallet::forceCreate([
        'competition_id' => 'COMP-'.fake()->unique()->numberBetween(1, 99999), 'cmp_uid' => (string) fake()->unique()->numberBetween(1, 99999),
        'game_type' => $gameType, 'customer_id' => $player->id, 'level' => 2, 'jp_rounds' => $rounds, 'balance' => $balance, 'status' => $status,
        'created_at' => $at->copy()->subHour(), 'updated_at' => $at,
    ]);

    CompetitionTransaction::forceCreate([
        'competition_wallet_id' => $wallet->id, 'customer_id' => $player->id, 'payment_type' => 'deposit', 'level' => 1,
        'amount' => $balance, 'status' => 2, 'created_at' => $at, 'updated_at' => $at,
    ]);

    return $wallet;
}

function openWalletsReport(object $test, string $query = ''): array
{
    return $test->getJson("/api/v1/finance/open-wallets{$query}", $test->headers)->assertOk()->json('data');
}

it('lists open wallets left idle past the threshold for their kind, longest idle first', function () {
    $waiting = openGame('G-WAITING', [[900, 50]], 45);
    $twoPlayer = openGame('G-TWO', [[900, 50], [901, 50], [900, 20]], 120);
    openGame('G-BUSY', [[900, 50], [901, 50]], 10);
    openGame('G-CLOSED', [[900, 50], [901, 50]], 500, status: 3);
    $tournament = openCompetition($this->alice, 1, 3, 90, 1500);
    openCompetition($this->bob, 1, 3, 90, 120);
    $jackpot = openCompetition($this->carol, 2, 13, 400, 3000);
    Complaint::factory()->create(['customer_id' => 902, 'subject_type' => Complaint::SUBJECT_JACKPOT, 'competition_wallet_id' => $jackpot->id, 'game_wallet_id' => null]);

    $data = openWalletsReport($this);

    expect(array_column($data['items'], 'wallet_id'))->toBe([$jackpot->id, $tournament->id, $twoPlayer->id, $waiting->id])
        ->and(array_column($data['items'], 'kind'))->toBe(['jackpot', 'tournament', 'game', 'game'])
        ->and($data['pagination']['total'])->toBe(4);

    expect($data['items'][2])->toMatchArray([
        'game_id' => 'G-TWO', 'cmp_uid' => null, 'players' => 2, 'rounds' => null, 'balance' => 120, 'status' => 1,
        'under_complaint' => false, 'idle_minutes' => 120,
        'last_activity_at' => '2026-10-16T10:00:00+03:00',
    ])->and($data['items'][2]['participants'])->toBe([
        ['customer_id' => 900, 'name' => 'Alice Wanjiru', 'stake' => 70],
        ['customer_id' => 901, 'name' => 'Bob Otieno', 'stake' => 50],
    ]);

    expect($data['items'][0])->toMatchArray([
        'kind' => 'jackpot', 'game_id' => null, 'cmp_uid' => $jackpot->cmp_uid, 'competition_id' => $jackpot->competition_id,
        'game_type' => 2, 'players' => null, 'rounds' => 13, 'level' => 2, 'balance' => 400, 'under_complaint' => true, 'idle_minutes' => 3000,
    ])->and($data['items'][0]['participants'])->toBe([['customer_id' => 902, 'name' => 'Carol Akinyi', 'stake' => 400]]);
});

it('summarises single games by players and competitions by rounds, filling the usual groups', function () {
    openGame('G-ONE', [[900, 50]], 45);
    openGame('G-TWO-A', [[900, 50], [901, 50]], 60);
    openGame('G-TWO-B', [[900, 100], [901, 100]], 90);
    openCompetition($this->alice, 1, 4, 80, 2000);
    openCompetition($this->bob, 2, 21, 300, 2000);

    $summary = openWalletsReport($this)['summary'];

    expect($summary['games'])->toMatchArray(['wallets' => 3, 'held' => 350, 'oldest_activity_at' => '2026-10-16T10:30:00+03:00'])
        ->and($summary['games']['by_players'])->toBe([
            ['players' => 1, 'wallets' => 1, 'held' => 50, 'oldest_activity_at' => '2026-10-16T11:15:00+03:00'],
            ['players' => 2, 'wallets' => 2, 'held' => 300, 'oldest_activity_at' => '2026-10-16T10:30:00+03:00'],
            ['players' => 3, 'wallets' => 0, 'held' => 0, 'oldest_activity_at' => null],
            ['players' => 4, 'wallets' => 0, 'held' => 0, 'oldest_activity_at' => null],
        ]);

    expect(array_column($summary['tournaments']['by_rounds'], 'rounds'))->toBe([3, 4, 5])
        ->and($summary['tournaments']['by_rounds'][1])->toMatchArray(['rounds' => 4, 'wallets' => 1, 'held' => 80])
        ->and(array_column($summary['jackpots']['by_rounds'], 'rounds'))->toBe([13, 17, 21])
        ->and($summary['jackpots']['by_rounds'][2])->toMatchArray(['rounds' => 21, 'wallets' => 1, 'held' => 300])
        ->and($summary['other_competitions'])->toMatchArray(['wallets' => 0, 'held' => 0])
        ->and($summary['total'])->toMatchArray(['wallets' => 5, 'held' => 730])
        ->and($summary['idle_minutes'])->toBe(['game' => 30, 'competition' => 1440]);
});

it('adds any unusual players or rounds value to the breakdown', function () {
    openGame('G-FIVE', [[900, 10], [901, 10], [902, 10], [2, 10], [null, 10]], 60);
    openCompetition($this->alice, 1, 13, 20, 2000);

    $summary = openWalletsReport($this, '?exclude_test=0')['summary'];

    expect(array_column($summary['games']['by_players'], 'players'))->toBe([1, 2, 3, 4, 5])
        ->and(array_column($summary['tournaments']['by_rounds'], 'rounds'))->toBe([3, 4, 5, 13]);
});

it('counts a stake left by a deleted customer as a player of its own', function () {
    openGame('G-ORPHAN', [[null, 50], [null, 50], [900, 50]], 60);

    $row = openWalletsReport($this)['items'][0];

    expect($row['players'])->toBe(3)
        ->and($row['participants'])->toBe([
            ['customer_id' => null, 'name' => null, 'stake' => 50],
            ['customer_id' => null, 'name' => null, 'stake' => 50],
            ['customer_id' => 900, 'name' => 'Alice Wanjiru', 'stake' => 50],
        ]);

    $game = collect(drillOpenGamesReport($this))->firstWhere('game_id', 'G-ORPHAN');
    expect($game['players'])->toBe(3);
});

function drillOpenGamesReport(object $test): array
{
    return $test->getJson('/api/v1/finance/games?from=2026-10-16&to=2026-10-16', $test->headers)->assertOk()->json('data.items');
}

it('filters by kind, players, rounds and an idle threshold of its own', function () {
    openGame('G-ONE', [[900, 50]], 45);
    openGame('G-TWO', [[900, 50], [901, 50]], 10);
    openCompetition($this->alice, 1, 3, 90, 1500);
    openCompetition($this->bob, 1, 5, 90, 1500);
    openCompetition($this->carol, 2, 13, 400, 120);

    $ids = fn (string $query) => array_column(openWalletsReport($this, $query)['items'], 'kind');

    expect($ids('?kind=game'))->toBe(['game'])
        ->and($ids('?kind=tournament'))->toBe(['tournament', 'tournament'])
        ->and($ids('?kind=jackpot'))->toBe([])
        ->and($ids('?kind=jackpot&idle_minutes=60'))->toBe(['jackpot'])
        ->and($ids('?idle_minutes=5'))->toBe(['tournament', 'tournament', 'jackpot', 'game', 'game'])
        ->and($ids('?players=1'))->toBe(['game'])
        ->and(array_column(openWalletsReport($this, '?jp_rounds=5')['items'], 'rounds'))->toBe([5]);

    expect(openWalletsReport($this, '?idle_minutes=5')['summary']['idle_minutes'])->toBe(['game' => 5, 'competition' => 5]);
});

it('leaves out wallets of test customers unless asked', function () {
    openGame('G-TEST', [[2, 50], [900, 50]], 60);
    openCompetition(Customer::find(2), 1, 3, 90, 2000);

    expect(openWalletsReport($this)['items'])->toBe([])
        ->and(openWalletsReport($this, '?exclude_test=0')['pagination']['total'])->toBe(2);
});

it('pages the rows but totals every wallet', function () {
    foreach (range(1, 3) as $i) {
        openGame("G-{$i}", [[900, 10], [901, 10]], 40 + $i);
    }

    $data = openWalletsReport($this, '?per_page=2&page=2');

    expect($data['items'])->toHaveCount(1)
        ->and($data['items'][0]['game_id'])->toBe('G-1')
        ->and($data['pagination'])->toMatchArray(['page' => 2, 'per_page' => 2, 'total' => 3, 'last_page' => 2])
        ->and($data['summary']['games']['wallets'])->toBe(3);
});

it('rejects an unknown kind or a bad idle threshold', function (string $query, string $field) {
    $this->getJson("/api/v1/finance/open-wallets{$query}", $this->headers)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'unknown kind' => ['?kind=coins', 'kind'],
    'negative idle' => ['?idle_minutes=-1', 'idle_minutes'],
    'idle not a number' => ['?idle_minutes=soon', 'idle_minutes'],
]);

it('exports every open wallet as CSV with the participants as JSON', function () {
    openGame('G-CSV', [[900, 50], [901, 50]], 60);
    openCompetition($this->alice, 2, 17, 200, 2000);

    $csv = $this->get('/api/v1/finance/export/open-wallets', $this->headers)->assertOk()->streamedContent();
    $rows = collect(explode("\n", trim(ltrim($csv, "\xEF\xBB\xBF"))))->map(fn (string $line) => str_getcsv($line));

    expect($rows->first())->toBe(['kind', 'wallet_id', 'game_id', 'cmp_uid', 'competition_id', 'game_type', 'players', 'rounds', 'level', 'balance', 'status', 'participants', 'under_complaint', 'created_at', 'last_activity_at', 'idle_minutes'])
        ->and($rows)->toHaveCount(3)
        ->and($rows[1][0])->toBe('jackpot')
        ->and($rows[2][2])->toBe('G-CSV')
        ->and(json_decode($rows[2][11], true))->toBe([
            ['customer_id' => 900, 'name' => 'Alice Wanjiru', 'stake' => 50],
            ['customer_id' => 901, 'name' => 'Bob Otieno', 'stake' => 50],
        ]);
});

it('needs an API key', function () {
    $this->getJson('/api/v1/finance/open-wallets')->assertUnauthorized();
});
