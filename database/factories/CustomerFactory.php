<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'id_no' => fake()->unique()->numberBetween(10000000, 99999999),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'phone_no' => fake()->phoneNumber(),
        ];
    }

    /**
     * A customer whose phone kadi has verified, as an M-Pesa withdrawal needs.
     */
    public function phoneVerified(): static
    {
        return $this->state(fn () => ['phone_no_verified_at' => now()]);
    }
}
