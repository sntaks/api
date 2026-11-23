<?php

namespace App\Domain\Jet;

use App\Models\Customer;
use App\Models\JetBet;
use App\Models\JetRound;
use App\Models\Wager;
use App\Services\WagerService;

/**
 * Places a bet on the round in its betting window. Runs inside OperationService (Jet fence held): the round row,
 * the jet bet, then WagerService::place (wager round, wallet). A refused wager throws, rolling everything back.
 */
class BetService
{
    public function __construct(private WagerService $wagers, private JetContext $context) {}

    public function place(Customer $customer, int $roundNumber, string $stake, ?string $autoCashout, string $wagerReference): JetBet
    {
        $round = JetRound::where('status', JetRound::STATUS_BETTING)->latest('id')->lockForUpdate()->first();

        if ($round && $round->round_number !== $roundNumber) {
            throw new JetException('ROUND_CHANGED', 409, 'reconcile');
        }

        if (! $round || ($round->betting_closes_at !== null && now()->greaterThanOrEqualTo($round->betting_closes_at))) {
            throw new JetException('ROUND_CLOSED', 409, 'reconcile');
        }

        $product = $this->context->product();
        $capHundredths = $this->capHundredths($stake, $product->max_payout, (string) $round->max_multiplier_cap);

        if ($capHundredths < 101) {
            throw new JetException('LIMIT_EXCEEDED', 422, 'correct_request', ['max_payout' => (string) $product->max_payout]);
        }

        $bet = JetBet::create([
            'jet_round_id' => $round->id,
            'customer_id' => $customer->id,
            'wager_reference' => $wagerReference,
            'channel' => 'web',
            'stake' => $stake,
            'auto_cashout' => $autoCashout,
            'cap_multiplier' => Money::fromHundredths($capHundredths),
            'status' => JetBet::STATUS_ACTIVE,
            'revision' => 1,
            'placed_at' => now(),
        ]);

        $placed = $this->wagers->place($this->context->wagerKey(), $customer, [
            'product' => $product->code,
            'reference' => $wagerReference,
            'funding' => Wager::FUNDING_CASH,
            'stake' => $stake,
            'round_reference' => (string) $round->round_number,
            'channel' => 'jet',
        ]);

        if ($placed->isRefused()) {
            throw WagerErrorMap::toException($placed);
        }

        $bet->forceFill(['wager_id' => $placed->wager->id])->save();
        EventPublisher::changed($customer->id);

        return $bet;
    }

    /**
     * The highest multiplier this stake may cash out at: max_payout / stake floored to two places, never above
     * the round's cap. A bet with no max_payout on the product is capped by the round alone.
     */
    private function capHundredths(string $stake, float|int|string|null $maxPayout, string $roundCap): int
    {
        $roundCapHundredths = Money::toHundredths($roundCap);
        $stakeHundredths = Money::toHundredths($stake);

        if ($maxPayout === null || $stakeHundredths <= 0) {
            return $roundCapHundredths;
        }

        return min($roundCapHundredths, intdiv(Money::toHundredths($maxPayout) * 100, $stakeHundredths));
    }
}
