<?php

namespace Database\Factories;

use App\Models\Game;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = fake()->unique()->lexify('product_????');

        return [
            'code' => $code,
            'game_id' => Game::factory(),
            'name' => ucfirst(str_replace('_', ' ', $code)),
            'revenue_model' => Product::REVENUE_HOUSE_BANKED,
            'status' => Product::STATUS_ACTIVE,
        ];
    }
}
