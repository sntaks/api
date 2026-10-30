<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Game;
use App\Models\LaunchToken;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LaunchToken>
 */
class LaunchTokenFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'game_id' => Game::factory(),
            'token_hash' => LaunchToken::hash(Str::random(40)),
            'expires_at' => now()->addSeconds(120),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subSecond()]);
    }

    public function redeemed(): static
    {
        return $this->state(fn () => ['redeemed_at' => now()]);
    }
}
