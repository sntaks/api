<?php

use App\Models\Game;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds the kadi game and its three products', function () {
    expect(Game::pluck('code')->all())->toBe(['kadi'])
        ->and(Product::orderBy('id')->pluck('code')->all())->toBe(['kadi', 'kadi_tournament', 'kadi_jackpot']);

    $kadi = Game::where('code', Game::CODE_KADI)->sole();

    expect($kadi->status)->toBe(Game::STATUS_ACTIVE)
        ->and(Product::where('game_id', '!=', $kadi->id)->exists())->toBeFalse()
        ->and(Product::pluck('revenue_model')->unique()->all())->toBe([Product::REVENUE_RAKE])
        ->and(Product::pluck('status')->unique()->all())->toBe([Product::STATUS_ACTIVE]);
});

it('finds game and product ids by code', function () {
    $kadi = Game::where('code', 'kadi')->sole();

    expect(Game::idFor('kadi'))->toBe($kadi->id)
        ->and(Product::idFor(Product::CODE_KADI_JACKPOT))->toBe(Product::where('code', 'kadi_jackpot')->value('id'))
        ->and(Product::idFor('nope'))->toBeNull();
});

it('creates a game and a product from the commands', function () {
    $this->artisan('game:create', ['code' => 'crash', 'name' => 'Crash', 'launch_url' => 'https://crash.kadikings.co.ke/launch'])
        ->assertSuccessful();
    $this->artisan('product:create', ['code' => 'crash', 'game' => 'crash', 'name' => 'Crash', '--max-payout' => '1000000'])
        ->assertSuccessful();

    $product = Product::where('code', 'crash')->sole();

    expect($product->revenue_model)->toBe(Product::REVENUE_HOUSE_BANKED)
        ->and($product->game->code)->toBe('crash')
        ->and($product->game->launch_url)->toBe('https://crash.kadikings.co.ke/launch')
        ->and($product->max_payout)->toBe('1000000.00')
        ->and($product->max_stake)->toBeNull()
        ->and($product->status)->toBe(Product::STATUS_ACTIVE);
});

it('refuses a product for an unknown game', function () {
    $this->artisan('product:create', ['code' => 'crash', 'game' => 'crash', 'name' => 'Crash'])
        ->assertFailed();

    expect(Product::where('code', 'crash')->exists())->toBeFalse();
});

it('refuses a duplicate code', function () {
    $this->artisan('game:create', ['code' => 'kadi', 'name' => 'Kadi again', 'launch_url' => 'https://kadikings.co.ke'])
        ->assertFailed();
    $this->artisan('product:create', ['code' => 'kadi', 'game' => 'kadi', 'name' => 'Kadi again'])
        ->assertFailed();

    expect(Game::where('code', 'kadi')->count())->toBe(1)
        ->and(Product::where('code', 'kadi')->count())->toBe(1);
});

it('refuses bad command input', function (string $command, array $arguments) {
    $this->artisan($command, $arguments)->assertFailed();

    expect(Game::count())->toBe(1)
        ->and(Product::count())->toBe(3);
})->with([
    'launch url not a url' => ['game:create', ['code' => 'crash', 'name' => 'Crash', 'launch_url' => 'not a url']],
    'code with spaces' => ['game:create', ['code' => 'cr ash', 'name' => 'Crash', 'launch_url' => 'https://crash.kadikings.co.ke']],
    'unknown revenue model' => ['product:create', ['code' => 'kadi_x', 'game' => 'kadi', 'name' => 'X', '--model' => 'pool']],
    'zero max stake' => ['product:create', ['code' => 'kadi_x', 'game' => 'kadi', 'name' => 'X', '--max-stake' => '0']],
]);
