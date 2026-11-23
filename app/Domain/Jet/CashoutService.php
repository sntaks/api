<?php

namespace App\Domain\Jet;

use App\Models\Customer;
use App\Models\JetBet;
use App\Models\JetRound;
use App\Models\WagerSettlement;
use App\Services\WagerService;

/**
 * A player's manual cash-out during the flight. Runs inside OperationService (Jet fence held): round, bet, then
 * WagerService::settle v1 won (wager, wallet, house). The multiplier comes from the server clock only; a bet
 * whose own target was already passed is paid at that target, as it would have been at settlement.
 */
class CashoutService
{
    public function __construct(private WagerService $wagers, private JetContext $context, private MultiplierCalculator $calculator) {}

    public function cashOut(Customer $customer, int $betId): JetBet
    {
        $found = JetBet::whereKey($betId)->where('customer_id', $customer->id)->first()
            ?? throw new JetException('NOT_FOUND', 404);

        $round = JetRound::whereKey($found->jet_round_id)->lockForUpdate()->firstOrFail();
        $bet = JetBet::whereKey($found->id)->lockForUpdate()->firstOrFail();

        if ($bet->status !== JetBet::STATUS_ACTIVE) {
            throw new JetException('ALREADY_SETTLED', 409, 'reconcile');
        }

        if ($round->status !== JetRound::STATUS_RUNNING || ! $round->started_at) {
            throw new JetException('ROUND_CLOSED', 409, 'reconcile');
        }

        $crash = (float) $round->crash_multiplier;
        $live = $this->calculator->currentMultiplier($round->started_at, (float) $round->acceleration_k, $crash);

        if (! is_finite($live) || $live < 1 || $live >= $crash) {
            throw new JetException('ROUND_CLOSED', 409, 'reconcile');
        }

        $target = $bet->targetMultiplier();

        if ($live >= (float) $target) {
            $multiplier = $target;
            $gross = Money::grossPayout((string) $bet->stake, $target);
        } else {
            // The crash server's policy: stake × live multiplier, rounded half-up to two places
            $multiplier = number_format(round($live, 2), 2, '.', '');
            $gross = number_format(round((float) $bet->stake * $live, 2), 2, '.', '');
        }

        $settled = $this->wagers->settle($this->context->wagerKey(), $this->context->productCode(), $bet->wager_reference, [
            'version' => 1,
            'outcome' => WagerSettlement::OUTCOME_WON,
            'payout' => $gross,
            'bonus' => 0,
        ]);

        if ($settled->isRefused()) {
            throw WagerErrorMap::toException($settled);
        }

        $bet->forceFill([
            'status' => JetBet::STATUS_WON,
            'revision' => $bet->revision + 1,
            'cashout_multiplier' => $multiplier,
            'payout' => $settled->settlement->payout,
            'tax' => $settled->settlement->tax,
            'net_payout' => Money::fromHundredths(Money::toHundredths($settled->settlement->payout) - Money::toHundredths($settled->settlement->tax)),
            'cashed_out_at' => now(),
            'settled_at' => now(),
        ])->save();

        EventPublisher::changed($customer->id);

        return $bet;
    }
}
