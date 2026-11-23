<?php

namespace Database\Factories;

use App\Models\CatalogStatusChange;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CatalogStatusChange>
 */
class CatalogStatusChangeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subject_type' => CatalogStatusChange::SUBJECT_PRODUCT,
            'subject_id' => Product::factory(),
            'from_status' => Product::STATUS_ACTIVE,
            'to_status' => Product::STATUS_SUSPENDED,
            'reason' => fake()->sentence(),
            'actor' => null,
            'admin_user' => fake()->userName(),
        ];
    }
}
