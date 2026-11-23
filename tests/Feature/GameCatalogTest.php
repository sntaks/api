<?php

use App\Models\CatalogStatusChange;
use App\Models\Game;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->headers = apiHeaders(createApiKey('test-game-catalog-key')->key);

    $this->crash = Game::factory()->create(['code' => 'crash', 'name' => 'Crash', 'sort_order' => 2]);
    Product::factory()->create(['code' => 'crash', 'game_id' => $this->crash->id, 'name' => 'Crash']);
    $sportsbook = Game::factory()->suspended()->create(['code' => 'sportsbook', 'name' => 'Sportsbook', 'sort_order' => 3]);
    Product::factory()->create(['code' => 'sportsbook', 'game_id' => $sportsbook->id, 'name' => 'Sportsbook']);
});

it('lists active games in sort order with their products', function () {
    $games = $this->getJson('/api/v1/games', $this->headers)->assertOk()->json('data');

    expect(collect($games)->pluck('code')->all())->toBe(['kadi', 'crash'])
        ->and(array_keys($games[1]))->toBe(['code', 'name', 'launch_url', 'status', 'sort_order', 'products'])
        ->and($games[1])->toMatchArray(['code' => 'crash', 'name' => 'Crash', 'launch_url' => 'https://crash.kadikings.co.ke/launch', 'status' => 'active'])
        ->and(collect($games[0]['products'])->pluck('code')->all())->toBe(['kadi', 'kadi_tournament', 'kadi_jackpot'])
        ->and($games[1]['products'][0])->toBe([
            'code' => 'crash',
            'game' => 'crash',
            'name' => 'Crash',
            'revenue_model' => 'house_banked',
            'status' => 'active',
            'min_stake' => null,
            'max_stake' => null,
            'max_payout' => null,
            'winnings_tax_rate' => 0,
        ]);
});

it('leaves suspended and retired products out of the lobby but not out of the full list', function () {
    Product::factory()->create(['code' => 'crash_turbo', 'game_id' => $this->crash->id, 'status' => Product::STATUS_SUSPENDED]);
    Product::factory()->create(['code' => 'crash_old', 'game_id' => $this->crash->id, 'status' => Product::STATUS_RETIRED]);

    expect($this->getJson('/api/v1/games', $this->headers)->assertOk()->json('data.1.products.*.code'))->toBe(['crash'])
        ->and($this->getJson('/api/v1/games?status=all', $this->headers)->assertOk()->json('data.1.products.*.code'))->toBe(['crash', 'crash_turbo', 'crash_old']);
});

it('lists every game when asked for all statuses', function () {
    $this->getJson('/api/v1/games?status=all', $this->headers)->assertOk()->assertJsonCount(3, 'data');
    $this->getJson('/api/v1/games?status=suspended', $this->headers)->assertOk()->assertJsonPath('data.0.code', 'sportsbook')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/games?status=nope', $this->headers)->assertUnprocessable();
});

it('needs an api key for the catalog', function () {
    $this->getJson('/api/v1/games')->assertUnauthorized();
    $this->putJson('/api/v1/products/crash', ['status' => 'suspended'])->assertUnauthorized();
});

it('updates a game', function () {
    $this->putJson('/api/v1/games/crash', ['name' => 'Aviator', 'launch_url' => 'https://aviator.kadikings.co.ke', 'status' => 'suspended', 'sort_order' => 9, 'reason' => 'Provider outage', 'admin_user' => 'ops.jane'], $this->headers)
        ->assertOk()
        ->assertJsonPath('data.name', 'Aviator')
        ->assertJsonPath('data.status', 'suspended');

    expect($this->crash->fresh())->name->toBe('Aviator')->launch_url->toBe('https://aviator.kadikings.co.ke')->sort_order->toBe(9)->code->toBe('crash')
        ->and(CatalogStatusChange::sole())->toMatchArray([
            'subject_type' => 'game', 'subject_id' => $this->crash->id, 'from_status' => 'active', 'to_status' => 'suspended',
            'reason' => 'Provider outage', 'admin_user' => 'ops.jane',
        ]);
});

it('validates a game update', function (array $payload) {
    $this->putJson('/api/v1/games/crash', $payload, $this->headers)->assertUnprocessable();
})->with([
    'bad url' => [['launch_url' => 'not a url']],
    'unknown status' => [['status' => 'paused']],
    'negative sort order' => [['sort_order' => -1]],
    'status without reason' => [['status' => 'suspended', 'admin_user' => 'ops.jane']],
    'status without admin' => [['status' => 'suspended', 'reason' => 'Provider outage']],
]);

it('lists products, optionally for one game', function () {
    $this->getJson('/api/v1/products', $this->headers)->assertOk()->assertJsonCount(5, 'data');

    $this->getJson('/api/v1/products?game=crash', $this->headers)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.code', 'crash');
});

it('suspends a product', function () {
    $this->putJson('/api/v1/products/crash', ['status' => 'suspended', 'reason' => 'Odds feed down', 'admin_user' => 'ops.jane'], $this->headers)
        ->assertOk()
        ->assertJsonPath('data.status', 'suspended');

    expect(Product::where('code', 'crash')->value('status'))->toBe(Product::STATUS_SUSPENDED)
        ->and(CatalogStatusChange::sole())->toMatchArray(['subject_type' => 'product', 'from_status' => 'active', 'to_status' => 'suspended']);
});

it('records no status change for other edits or an unchanged status', function () {
    $this->putJson('/api/v1/products/crash', ['name' => 'Crash X'], $this->headers)->assertOk();
    $this->putJson('/api/v1/products/crash', ['status' => 'active', 'reason' => 'Check', 'admin_user' => 'ops.jane'], $this->headers)->assertOk();

    expect(CatalogStatusChange::count())->toBe(0);
});

it('lists the status history newest first', function () {
    foreach (['suspended', 'active'] as $status) {
        $this->putJson('/api/v1/games/crash', ['status' => $status, 'reason' => "Set {$status}", 'admin_user' => 'ops.jane'], $this->headers)->assertOk();
        $this->travel(1)->minutes();
    }

    $this->getJson('/api/v1/games/crash/status-changes', $this->headers)
        ->assertOk()
        ->assertJsonPath('data.items.0.to_status', 'active')
        ->assertJsonPath('data.items.1.to_status', 'suspended')
        ->assertJsonPath('data.items.1.reason', 'Set suspended');
    $this->getJson('/api/v1/products/crash/status-changes', $this->headers)->assertOk()->assertJsonCount(0, 'data.items');
    $this->getJson('/api/v1/products/nope/status-changes', $this->headers)->assertNotFound();
});

it('sets product limits and the winnings tax rate', function () {
    $this->putJson('/api/v1/products/crash', ['max_stake' => 20000, 'max_payout' => 1000000.5, 'winnings_tax_rate' => 0.2], $this->headers)
        ->assertOk()
        ->assertJsonPath('data.max_stake', 20000)
        ->assertJsonPath('data.max_payout', 1000000.5)
        ->assertJsonPath('data.winnings_tax_rate', 0.2);

    $this->putJson('/api/v1/products/crash', ['max_stake' => null], $this->headers)->assertOk()->assertJsonPath('data.max_stake', null);
});

it('validates product limits', function (array $payload) {
    $this->putJson('/api/v1/products/crash', $payload, $this->headers)->assertUnprocessable();
})->with([
    'max stake 0' => [['max_stake' => 0]],
    'max stake -1' => [['max_stake' => -1]],
    'max stake 1.001' => [['max_stake' => 1.001]],
    'tax rate above 1' => [['winnings_tax_rate' => 1.5]],
    'unknown status' => [['status' => 'paused', 'reason' => 'Paused', 'admin_user' => 'ops.jane']],
    'status without reason' => [['status' => 'suspended', 'admin_user' => 'ops.jane']],
]);

it('answers 404 for an unknown code', function () {
    $this->putJson('/api/v1/games/nope', ['status' => 'suspended', 'reason' => 'Gone', 'admin_user' => 'ops.jane'], $this->headers)->assertNotFound();
    $this->putJson('/api/v1/products/nope', ['status' => 'suspended', 'reason' => 'Gone', 'admin_user' => 'ops.jane'], $this->headers)->assertNotFound();
});
