<?php

namespace App\Services;

use App\Models\Customer;

/**
 * The one place that decides whether a player may play, deposit or withdraw. Only an active player (status 1)
 * who is not on a break plays or deposits. A suspended player (blocked or fraud) cannot withdraw either; every
 * other player, on a break or not, may always take their money out. Money owed to a player (settlements,
 * payouts, refunds) never passes this gate.
 */
class PlayerGate
{
    public function __construct(private DepositAllowance $allowance) {}

    public function canPlay(Customer $customer): GateResult
    {
        if ($customer->trashed() || ! $customer->isActive()) {
            return GateResult::refuse('player_inactive', 'This player cannot play', 403);
        }

        $exclusion = $customer->activeExclusion();

        if ($exclusion) {
            return GateResult::refuse('player_excluded', 'This player is taking a break from play', 403, [
                'excluded_until' => $exclusion->ends_at->toIso8601String(),
            ]);
        }

        return GateResult::allow();
    }

    /**
     * As canPlay, and the amount must fit what the player's own deposit limits leave.
     */
    public function canDeposit(Customer $customer, float $amount): GateResult
    {
        $canPlay = $this->canPlay($customer);

        if (! $canPlay->allowed) {
            return $canPlay;
        }

        $remaining = $this->allowance->remaining($customer);

        if ($remaining !== null && (int) round($amount * 100) > (int) round($remaining * 100)) {
            return GateResult::refuse('deposit_limit', 'This deposit is above what the deposit limits allow now', 422, [
                'remaining' => $remaining,
            ]);
        }

        return GateResult::allow();
    }

    public function canWithdraw(Customer $customer): GateResult
    {
        if ($customer->isSuspended()) {
            return GateResult::refuse('player_suspended', 'This account is suspended. Please contact support.', 403);
        }

        return GateResult::allow();
    }
}
