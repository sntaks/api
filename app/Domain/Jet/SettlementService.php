<?php

namespace App\Domain\Jet;

use App\Models\JetBet;
use App\Models\JetRound;
use App\Models\Wager;
use App\Models\WagerSettlement;
use App\Services\WagerRoundService;
use RuntimeException;

/**
 * Pays a crashed round and voids a broken one through WagerRoundService. The money calls run OUTSIDE the Jet
 * fence: nothing can change a crashed or voiding round's bets any more (cash-outs need a running round), so a
 * large round does not hold up the next one's bets. WagerRoundService is idempotent, so a retry after a crash
 * re-runs it safely; the jet rows are then copied from the wagers under the fence.
 */
class SettlementService
{
    public function __construct(private WagerRoundService $rounds, private JetContext $context) {}

    /**
     * Every active bet whose target is strictly below the crash point wins at its target (equality loses);
     * every other open wager of the round is lost.
     */
    public function settle(JetRound $round): JetRound
    {
        if ($round->status !== JetRound::STATUS_CRASHED) {
            throw new RuntimeException("Jet round {$round->round_number} is {$round->status}, not crashed");
        }

        $result = $this->rounds->settle($this->context->wagerKey(), $this->context->productCode(), (string) $round->round_number, $this->winners($round), true);

        if ($result->refusal !== null) {
            throw new RuntimeException("Jet round {$round->round_number} settlement refused: {$result->refusal['code']}");
        }

        return JetLock::run(function () use ($round) {
            $locked = JetRound::whereKey($round->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== JetRound::STATUS_CRASHED) {
                return $locked;
            }

            $this->copyOutcomes($locked);
            $locked->forceFill(['status' => JetRound::STATUS_SETTLED, 'settled_at' => now(), 'revision' => $locked->revision + 1])->save();
            EventPublisher::changed();

            return $locked;
        });
    }

    /**
     * Takes a round out of play at once (no more bets or cash-outs), then refunds its open wagers. A bet already
     * cashed out keeps its win. Refused for a round that is already settled or voided.
     */
    public function void(JetRound $round, string $reason, string $voidedBy): JetRound
    {
        $round = JetLock::run(function () use ($round, $reason, $voidedBy) {
            $locked = JetRound::whereKey($round->id)->lockForUpdate()->firstOrFail();

            if ($locked->isFinished()) {
                throw new JetException('ALREADY_SETTLED', 409, 'reconcile');
            }

            if ($locked->status !== JetRound::STATUS_VOIDING) {
                $locked->forceFill([
                    'status' => JetRound::STATUS_VOIDING,
                    'void_reason' => $reason,
                    'voided_by' => $voidedBy,
                    'revision' => $locked->revision + 1,
                ])->save();
                EventPublisher::changed();
            }

            return $locked;
        });

        return $this->completeVoid($round);
    }

    public function completeVoid(JetRound $round): JetRound
    {
        $result = $this->rounds->void($this->context->wagerKey(), $this->context->productCode(), (string) $round->round_number, $round->void_reason);

        if ($result->refusal !== null) {
            throw new RuntimeException("Jet round {$round->round_number} void refused: {$result->refusal['code']}");
        }

        return JetLock::run(function () use ($round) {
            $locked = JetRound::whereKey($round->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== JetRound::STATUS_VOIDING) {
                return $locked;
            }

            $this->copyOutcomes($locked);
            $locked->forceFill(['status' => JetRound::STATUS_VOIDED, 'voided_at' => now(), 'revision' => $locked->revision + 1])->save();
            EventPublisher::changed();

            return $locked;
        });
    }

    /**
     * @return list<array{reference: string, payout: string}>
     */
    public function winners(JetRound $round): array
    {
        $crash = (string) $round->crash_multiplier;

        return JetBet::where('jet_round_id', $round->id)->where('status', JetBet::STATUS_ACTIVE)->orderBy('id')->get()
            ->filter(fn (JetBet $bet) => bccomp($bet->targetMultiplier(), $crash, 2) < 0)
            ->map(fn (JetBet $bet) => ['reference' => $bet->wager_reference, 'payout' => Money::grossPayout((string) $bet->stake, $bet->targetMultiplier())])
            ->values()->all();
    }

    /**
     * Copies each still-active bet's outcome from its wager's applied settlement. Runs under the fence.
     */
    private function copyOutcomes(JetRound $round): void
    {
        $bets = JetBet::where('jet_round_id', $round->id)->where('status', JetBet::STATUS_ACTIVE)->orderBy('id')->lockForUpdate()->get();
        $wagers = Wager::whereKey($bets->pluck('wager_id'))->get()->keyBy('id');
        $settlements = WagerSettlement::whereIn('wager_id', $bets->pluck('wager_id'))->where('status', WagerSettlement::STATUS_APPLIED)
            ->orderBy('version')->get()->keyBy('wager_id');
        $changedCustomers = [];

        foreach ($bets as $bet) {
            $wager = $wagers->get($bet->wager_id);
            $settlement = $settlements->get($bet->wager_id);
            $status = match ($wager?->status) {
                Wager::STATUS_WON => JetBet::STATUS_WON,
                Wager::STATUS_LOST => JetBet::STATUS_LOST,
                Wager::STATUS_VOID => JetBet::STATUS_VOID,
                default => null,
            };

            if ($status === null || ! $settlement) {
                continue;
            }

            $won = $status === JetBet::STATUS_WON;
            $bet->forceFill([
                'status' => $status,
                'revision' => $bet->revision + 1,
                'cashout_multiplier' => $won ? $bet->targetMultiplier() : null,
                'payout' => $settlement->payout,
                'tax' => $settlement->tax,
                'net_payout' => Money::fromHundredths(Money::toHundredths($settlement->payout) - Money::toHundredths($settlement->tax)),
                'cashed_out_at' => $won ? now() : null,
                'settled_at' => now(),
            ])->save();

            $changedCustomers[$bet->customer_id] = true;
        }

        foreach (array_keys($changedCustomers) as $customerId) {
            EventPublisher::changed($customerId);
        }
    }
}
