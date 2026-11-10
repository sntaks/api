<?php

namespace Database\Factories;

use App\Models\PredictMarket;
use App\Models\PredictPool;
use App\Models\Product;
use App\Models\WagerRound;
use App\Services\Predict\PoolMath;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A custom pool market. Drafts by default; `open()` publishes it the way MarketService does (pool + round).
 *
 * @extends Factory<PredictMarket>
 */
class PredictMarketFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source' => PredictMarket::SOURCE_CUSTOM,
            'question' => fake()->sentence().'?',
            'description' => fake()->paragraph(),
            'resolution_criteria' => 'Resolves YES if '.fake()->sentence(),
            'resolution_source' => fake()->url(),
            'category' => 'sport',
            'countries' => ['KE'],
            'status' => PredictMarket::STATUS_DRAFT,
            'engine' => PredictMarket::ENGINE_POOL,
            'product_id' => Product::factory(),
            'round_reference' => 'predict-'.Str::lower((string) Str::ulid()),
            'close_at' => now()->addDays(3),
            'resolution_deadline_at' => now()->addDays(4),
            'cut_bp' => 1000,
            'seed_minor' => 500000,
            'start_probability_bp' => 5000,
            'min_stake_minor' => 1000,
            'max_stake_minor' => 500000,
            'max_payout_minor' => 25000000,
            'min_multiplier_x100' => 105,
            'created_by' => 'factory',
        ];
    }

    /**
     * Published: pool seeded and wager round created.
     */
    public function open(): static
    {
        return $this->state(['status' => PredictMarket::STATUS_OPEN, 'opened_at' => now()])
            ->afterCreating(function (PredictMarket $market) {
                $seed = PoolMath::seedSplit($market->seed_minor, $market->start_probability_bp);

                PredictPool::create([
                    'predict_market_id' => $market->id,
                    'yes_minor' => $seed['yes'],
                    'no_minor' => $seed['no'],
                    'seed_yes_minor' => $seed['yes'],
                    'seed_no_minor' => $seed['no'],
                ]);

                WagerRound::create(['product_id' => $market->product_id, 'reference' => $market->round_reference, 'status' => WagerRound::STATUS_OPEN]);
            });
    }
}
