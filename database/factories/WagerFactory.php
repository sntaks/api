<?php

namespace Database\Factories;

use App\Models\ApiKey;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Wager;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An open cash wager whose stake sits in its own escrow balance.
 *
 * @extends Factory<Wager>
 */
class WagerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'customer_id' => Customer::factory(),
            'wallet_id' => fn (array $attributes) => Wallet::forceCreate(['customer_id' => $attributes['customer_id'], 'balance' => 0])->id,
            'api_key_id' => fn () => ApiKey::create(['key' => fake()->unique()->uuid(), 'name' => 'Wager test key'])->id,
            'reference' => fake()->unique()->bothify('wager-########'),
            'funding' => Wager::FUNDING_CASH,
            'stake' => 100,
            'free_bet_amount' => 0,
            'balance' => fn (array $attributes) => $attributes['stake'],
            'status' => Wager::STATUS_OPEN,
            'placed_at' => now(),
        ];
    }

    public function freeBet(float $amount = 100): static
    {
        return $this->state(fn () => [
            'funding' => Wager::FUNDING_FREE_BET,
            'stake' => 0,
            'free_bet_amount' => $amount,
            'balance' => 0,
        ]);
    }
}
