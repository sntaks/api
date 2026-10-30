<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Withdraw;
use App\Models\WithdrawalApproval;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WithdrawalApproval>
 */
class WithdrawalApprovalFactory extends Factory
{
    /**
     * A KES 25,000 withdrawal waiting for approval. Only the rows are made: no ledger entry, no wallet change.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'withdraw_id' => fn () => Withdraw::create(['amount' => 25_000, 'disburse' => 1])->id,
            'customer_id' => Customer::factory(),
            'amount' => 25_000,
            'phone_no' => '254712345678',
            'status' => WithdrawalApproval::STATUS_AWAITING,
        ];
    }
}
