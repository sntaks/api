<?php

namespace Database\Factories;

use App\Models\Game;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Game>
 */
class GameFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = fake()->unique()->lexify('game_????');

        return [
            'code' => $code,
            'name' => ucfirst(str_replace('_', ' ', $code)),
            'launch_url' => fn (array $attributes) => "https://{$attributes['code']}.kadikings.co.ke/launch",
            'status' => Game::STATUS_ACTIVE,
            'sort_order' => fake()->numberBetween(2, 50),
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => Game::STATUS_SUSPENDED]);
    }
}
