<?php

namespace Database\Factories;

use App\Models\WinningsTaxRemittance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WinningsTaxRemittance>
 */
class WinningsTaxRemittanceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $amount = fake()->randomFloat(2, 100, 50000);

        return [
            'period_start' => now()->subMonthNoOverflow()->startOfMonth()->toDateString(),
            'period_end' => now()->subMonthNoOverflow()->endOfMonth()->toDateString(),
            'amount_due' => $amount,
            'amount_paid' => $amount,
            'kra_reference' => strtoupper(fake()->bothify('PRN##########')),
            'paid_at' => now(),
            'recorded_by' => 'api_key:1',
        ];
    }
}
