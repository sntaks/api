<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\CustomerExclusion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerExclusion>
 */
class CustomerExclusionFactory extends Factory
{
    /**
     * A 7-day cool-off that started now.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'type' => CustomerExclusion::TYPE_COOL_OFF,
            'duration' => '7d',
            'starts_at' => now(),
            'ends_at' => now()->addDays(7),
        ];
    }
}
