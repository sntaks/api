<?php

namespace App\Services;

use App\Models\Customer;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The rules every M-Pesa withdrawal follows, main and referral wallet alike (D14, D17): the player is not
 * suspended, the phone the money goes to is verified, and at most withdrawals_per_hour per player per hour,
 * counted across both wallets. The amount range is validated by the routes.
 */
class WithdrawalRules
{
    public function __construct(private PlayerGate $gate) {}

    public function check(Customer $customer): GateResult
    {
        $canWithdraw = $this->gate->canWithdraw($customer);

        if (! $canWithdraw->allowed) {
            return $canWithdraw;
        }

        if ($customer->phone_no_verified_at === null) {
            return GateResult::refuse('phone_unverified', 'Verify your phone number on kadi before withdrawing', 422);
        }

        $key = $this->limiterKey($customer);

        if (RateLimiter::tooManyAttempts($key, (int) config('responsible_gambling.withdrawals_per_hour'))) {
            return GateResult::refuse('too_many_withdrawals', 'Too many withdrawals. Please try again later.', 429, [
                'retry_after' => RateLimiter::availableIn($key),
            ]);
        }

        return GateResult::allow();
    }

    /**
     * Count a withdrawal against the hourly cap. Called once the money is reserved, before M-Pesa is asked to
     * pay, so a payout that hangs or fails still counts.
     */
    public function record(Customer $customer): void
    {
        RateLimiter::hit($this->limiterKey($customer), 3600);
    }

    /**
     * @return list<string>
     */
    public static function amountRules(): array
    {
        return ['required', 'integer', 'min:'.config('responsible_gambling.withdrawal_min'), 'max:'.config('responsible_gambling.withdrawal_max')];
    }

    private function limiterKey(Customer $customer): string
    {
        return 'withdrawals:'.$customer->id;
    }
}
