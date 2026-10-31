<?php

use App\Models\ApiKey;
use App\Models\Customer;
use App\Models\Game;
use App\Models\Product;
use App\Models\Wager;
use App\Models\Wallet;
use App\Services\PlayerTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->game = Game::factory()->create(['code' => 'scratch']);
    $this->key = ApiKey::create(['key' => 'game_scratch_key', 'name' => 'scratch', 'scope' => ApiKey::SCOPE_GAME, 'game_id' => $this->game->id, 'is_active' => true]);
    $this->card = Product::factory()->create(['game_id' => $this->game->id, 'code' => 'scratch_50', 'min_stake' => 50, 'max_stake' => 50]);
    Wallet::forceCreate(['id' => 1, 'customer_id' => Customer::factory()->create(['id' => 1])->id, 'balance' => 10000]);
    $this->player = Customer::factory()->create(['status' => 1]);
    $this->wallet = Wallet::forceCreate(['customer_id' => $this->player->id, 'balance' => 500]);
    $this->token = app(PlayerTokenService::class)->issue($this->player, $this->game)->plainTextToken;
    $this->gms = apiHeaders(createApiKey('test-min-stake-gms')->key);
});

function buyCard(object $test, string $route, array $overrides = [])
{
    $payload = array_merge(['product' => 'scratch_50', 'reference' => 'card-'.uniqid(), 'funding' => 'cash', 'stake' => 50], $overrides);
    if ($route === 'instant') {
        $payload += ['outcome' => 'lost', 'payout' => 0];
    }

    $path = $route === 'instant' ? '/api/v1/wagers/instant' : '/api/v1/wagers';

    return $test->postJson($path, array_filter($payload, fn ($value) => $value !== null), apiHeaders($test->key->key) + ['Authorization' => 'Bearer '.$test->token]);
}

it('refuses a stake below the product minimum', function (string $route) {
    buyCard($this, $route, ['stake' => 49])
        ->assertStatus(422)
        ->assertJsonPath('code', 'stake_below_limit')
        ->assertJsonPath('min_stake', 50);

    expect(Wager::count())->toBe(0)->and((float) $this->wallet->fresh()->balance)->toBe(500.0);
})->with(['place', 'instant']);

it('accepts only the card price when min and max stake are equal', function () {
    buyCard($this, 'instant')->assertCreated();
    buyCard($this, 'instant', ['stake' => 51])->assertStatus(422)->assertJsonPath('code', 'stake_above_limit');

    expect(Wager::count())->toBe(1)->and((float) $this->wallet->fresh()->balance)->toBe(450.0);
});

it('does not apply the minimum to a free card', function () {
    buyCard($this, 'instant', ['funding' => 'free_bet', 'stake' => null, 'free_bet_amount' => 50])->assertCreated();
});

it('validates min stake against max stake', function () {
    $this->putJson('/api/v1/products/scratch_50', ['min_stake' => 60], $this->gms)->assertStatus(422)->assertJsonValidationErrors('min_stake');
    $this->putJson('/api/v1/products/scratch_50', ['min_stake' => 20, 'max_stake' => 10], $this->gms)->assertStatus(422)->assertJsonValidationErrors('min_stake');

    $this->putJson('/api/v1/products/scratch_50', ['min_stake' => 100, 'max_stake' => 100], $this->gms)
        ->assertOk()
        ->assertJsonPath('data.min_stake', 100);
    $this->putJson('/api/v1/products/scratch_50', ['min_stake' => null], $this->gms)->assertOk()->assertJsonPath('data.min_stake', null);
});

it('sets a minimum stake when a product is created', function () {
    $this->artisan('product:create', ['code' => 'scratch_100', 'game' => 'scratch', 'name' => 'Scratch 100', '--min-stake' => 100, '--max-stake' => 100])->assertSuccessful();
    $this->artisan('product:create', ['code' => 'scratch_bad', 'game' => 'scratch', 'name' => 'Bad', '--min-stake' => 200, '--max-stake' => 100])->assertFailed();
    $this->artisan('product:create', ['code' => 'slots_min_only', 'game' => 'scratch', 'name' => 'Min only', '--min-stake' => 10])->assertSuccessful();

    expect((float) Product::where('code', 'scratch_100')->sole()->min_stake)->toBe(100.0)
        ->and(Product::where('code', 'scratch_bad')->exists())->toBeFalse();
});
