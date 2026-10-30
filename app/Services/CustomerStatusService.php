<?php

namespace App\Services;

use App\Models\Customer;
use Illuminate\Support\Facades\DB;

/**
 * Changes a customer's status and records who did it and why. A player who stops being active leaves every
 * game at once (the Customer model's updated hook revokes their player tokens).
 */
class CustomerStatusService
{
    /**
     * @return int|null The previous status
     */
    public function change(Customer $customer, int $to, ?string $reason, ?string $actor, ?string $adminUser = null): ?int
    {
        $from = $customer->status === null ? null : (int) $customer->status;

        if ($from === $to) {
            return $from;
        }

        DB::transaction(function () use ($customer, $from, $to, $reason, $actor, $adminUser) {
            $customer->update(['status' => $to]);

            DB::table('customer_status_changes')->insert([
                'customer_id' => $customer->id,
                'from_status' => $from,
                'to_status' => $to,
                'reason' => $reason,
                'actor' => $actor,
                'admin_user' => $adminUser,
                'created_at' => now(),
            ]);
        });

        return $from;
    }
}
