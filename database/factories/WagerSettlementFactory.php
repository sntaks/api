<?php

namespace Database\Factories;

use App\Models\Wager;
use App\Models\WagerSettlement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WagerSettlement>
 */
class WagerSettlementFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'wager_id' => Wager::factory(),
            'version' => 1,
            'outcome' => WagerSettlement::OUTCOME_LOST,
            'settled_by' => WagerSettlement::SETTLED_BY_GAME,
            'status' => WagerSettlement::STATUS_APPLIED,
        ];
    }

    public function won(float $payout, float $bonus = 0, float $tax = 0): static
    {
        return $this->state(fn () => [
            'outcome' => WagerSettlement::OUTCOME_WON,
            'payout' => $payout,
            'bonus' => $bonus,
            'tax' => $tax,
        ]);
    }

    public function void(): static
    {
        return $this->state(fn () => ['outcome' => WagerSettlement::OUTCOME_VOID]);
    }
}
