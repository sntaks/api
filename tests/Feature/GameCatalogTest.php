<?php

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
    $this->putJson('/api/v1/games/crash', ['name' => 'Aviator', 'launch_url' => 'https://aviator.kadikings.co.ke', 'status' => 'suspended', 'sort_order' => 9], $this->headers)
        ->assertOk()
        ->assertJsonPath('data.name', 'Aviator')
        ->assertJsonPath('data.status', 'suspended');

    expect($this->crash->fresh())->name->toBe('Aviator')->launch_url->toBe('https://aviator.kadikings.co.ke')->sort_order->toBe(9)->code->toBe('crash');
});

it('validates a game update', function (array $payload) {
    $this->putJson('/api/v1/games/crash', $payload, $this->headers)->assertUnprocessable();
})->with([
    'bad url' => [['launch_url' => 'not a url']],
    'unknown status' => [['status' => 'paused']],
    'negative sort order' => [['sort_order' => -1]],
]);

it('lists products, optionally for one game', function () {
    $this->getJson('/api/v1/products', $this->headers)->assertOk()->assertJsonCount(5, 'data');

    $this->getJson('/api/v1/products?game=crash', $this->headers)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.code', 'crash');
});

it('suspends a product', function () {
    $this->putJson('/api/v1/products/crash', ['status' => 'suspended'], $this->headers)
        ->assertOk()
        ->assertJsonPath('data.status', 'suspended');

    expect(Product::where('code', 'crash')->value('status'))->toBe(Product::STATUS_SUSPENDED);
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
    'unknown status' => [['status' => 'paused']],
]);

it('answers 404 for an unknown code', function () {
    $this->putJson('/api/v1/games/nope', ['status' => 'suspended'], $this->headers)->assertNotFound();
    $this->putJson('/api/v1/products/nope', ['status' => 'suspended'], $this->headers)->assertNotFound();
});
