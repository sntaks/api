<?php

namespace Database\Factories;

use App\Models\LedgerEntry;
use App\Models\Wager;
use App\Models\WagerSettlement;
use App\Models\WinningsTaxCharge;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A winnings tax charge on a won settlement of a fresh wager, with its winnings_tax ledger entry.
 *
 * @extends Factory<WinningsTaxCharge>
 */
class WinningsTaxChargeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'wager_settlement_id' => fn (array $attributes) => WagerSettlement::factory()
                ->won(300, 0, abs((float) $attributes['amount']))
                ->create(['tax_base' => $attributes['tax_base'], 'tax_rate' => $attributes['rate']])->id,
            'kind' => WinningsTaxCharge::KIND_CHARGE,
            'wager_id' => fn (array $attributes) => WagerSettlement::find($attributes['wager_settlement_id'])->wager_id,
            'product_id' => fn (array $attributes) => Wager::find($attributes['wager_id'])->product_id,
            'customer_id' => fn (array $attributes) => Wager::find($attributes['wager_id'])->customer_id,
            'wallet_id' => fn (array $attributes) => Wager::find($attributes['wager_id'])->wallet_id,
            'ledger_entry_id' => fn (array $attributes) => LedgerEntry::create([
                'entry_id' => (string) Str::uuid(),
                'entry_type' => $attributes['kind'] === WinningsTaxCharge::KIND_REVERSAL ? 'winnings_tax_reversal' : 'winnings_tax',
                'wallet_type' => LedgerEntry::WALLET_TYPE_WALLET,
                'wallet_id' => $attributes['wallet_id'],
                'customer_id' => $attributes['customer_id'],
                'referenceable_type' => WagerSettlement::class,
                'referenceable_id' => $attributes['wager_settlement_id'],
                'debit' => max(0, (float) $attributes['amount']),
                'credit' => max(0, -(float) $attributes['amount']),
                'balance_before' => 0,
                'balance_after' => -(float) $attributes['amount'],
                'status' => 'settled',
            ])->id,
            'tax_base' => 200,
            'rate' => 0.2,
            'amount' => 40,
            'charged_at' => now(),
        ];
    }

    /**
     * The credit row of a reversed charge: the same settlement, a negative amount.
     */
    public function reversalOf(WinningsTaxCharge $charge): static
    {
        return $this->state(fn () => [
            'wager_settlement_id' => $charge->wager_settlement_id,
            'kind' => WinningsTaxCharge::KIND_REVERSAL,
            'tax_base' => $charge->tax_base,
            'rate' => $charge->rate,
            'amount' => -(float) $charge->amount,
        ]);
    }
}
