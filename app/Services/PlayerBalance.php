<?php

namespace App\Services;

use App\Models\Customer;

/**
 * A player's wallet as a game sees it. The locked signup bonus is shown on its own: it only blocks
 * withdrawals, so a game may stake the whole balance. The balance can be negative after a settlement
 * reversal, and then the player cannot stake.
 */
class PlayerBalance
{
    public function __construct(private SignupBonusService $signupBonus) {}

    /**
     * @return array{balance: float, locked_bonus: float, wallet_version: int}
     */
    public function for(Customer $customer): array
    {
        $wallet = $customer->wallet()->first();

        return [
            'balance' => round((float) ($wallet->balance ?? 0), 2),
            'locked_bonus' => $wallet ? $this->signupBonus->lockedAmount($customer->id) : 0.0,
            'wallet_version' => (int) ($wallet->wallet_version ?? 0),
        ];
    }
}
