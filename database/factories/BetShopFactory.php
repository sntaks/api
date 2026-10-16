<?php

namespace Database\Factories;

use App\Models\BetShop;
use App\Models\BetShopWallet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BetShop>
 */
class BetShopFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shop_no' => 'S'.fake()->unique()->numberBetween(100, 99999),
            'name' => fake()->company().' Bet Shop',
            'owner_name' => fake()->name(),
            'owner_phone' => '2547'.fake()->numerify('########'),
            'location' => fake()->city(),
            'status' => BetShop::STATUS_ACTIVE,
        ];
    }

    /**
     * Give the shop its float wallet, holding $balance.
     */
    public function withFloat(float $balance = 0): static
    {
        return $this->afterCreating(fn (BetShop $shop) => BetShopWallet::create(['bet_shop_id' => $shop->id, 'balance' => $balance]));
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => BetShop::STATUS_SUSPENDED, 'status_reason' => 'Under review']);
    }
}
