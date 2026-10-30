<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\WagerRound;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WagerRound>
 */
class WagerRoundFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'reference' => fake()->unique()->bothify('round-########'),
            'status' => WagerRound::STATUS_OPEN,
        ];
    }
}
