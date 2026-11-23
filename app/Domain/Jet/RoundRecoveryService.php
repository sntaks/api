<?php

namespace App\Domain\Jet;

use App\Models\JetRound;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The game loop's database lease (one owner, renewed under the fence on every tick) and the tick itself, which
 * resumes the oldest unfinished round from its persisted deadlines. It never creates a round while one is
 * unfinished, and creates none while jet_classic or its game is not available (a running round still finishes).
 */
class RoundRecoveryService
{
    private ?string $owner = null;

    public function __construct(
        private RoundService $rounds,
        private SettlementService $settlement,
        private MultiplierCalculator $calculator,
        private JetContext $context,
    ) {}

    public function ownsLease(): bool
    {
        return $this->owner !== null;
    }

    public function acquire(): bool
    {
        return JetLock::run(function () {
            $state = DB::table('jet_contract_state')->where('id', 1)->first();

            if ($state->loop_owner && $state->lease_until > now()->format('Y-m-d H:i:s.u')) {
                return false;
            }

            $this->owner = (string) Str::uuid();
            DB::table('jet_contract_state')->where('id', 1)->update(['loop_owner' => $this->owner, 'lease_until' => $this->leaseUntil()]);

            return true;
        });
    }

    public function fenced(callable $callback): mixed
    {
        return JetLock::run(function () use ($callback) {
            $state = DB::table('jet_contract_state')->where('id', 1)->first();

            if (! $this->owner || $state->loop_owner !== $this->owner || $state->lease_until <= now()->format('Y-m-d H:i:s.u')) {
                throw new JetException('LOOP_LEASE_LOST', 409, 'backoff');
            }

            DB::table('jet_contract_state')->where('id', 1)->update(['lease_until' => $this->leaseUntil()]);

            return $callback();
        });
    }

    public function release(): void
    {
        if ($this->owner === null) {
            return;
        }

        JetLock::run(fn () => DB::table('jet_contract_state')->where('id', 1)->where('loop_owner', $this->owner)->update(['loop_owner' => null, 'lease_until' => null]));
        $this->owner = null;
    }

    public function tick(bool $create = false): ?JetRound
    {
        $round = $this->fenced(function () use ($create) {
            $round = JetRound::whereNotIn('status', JetRound::FINISHED)->oldest('id')->first();

            if (! $round) {
                if (! $create || ! $this->context->product()->isAvailable()) {
                    return null;
                }

                $round = $this->rounds->createRound();
            }

            if ($round->status === JetRound::STATUS_SCHEDULED) {
                $round = $this->rounds->openBetting($round);
            }

            if ($round->status === JetRound::STATUS_BETTING && $round->betting_closes_at->lessThanOrEqualTo(now())) {
                $round = $this->rounds->startRound($round);
            }

            if ($round->status === JetRound::STATUS_RUNNING) {
                $delay = $this->calculator->crashDelaySeconds((float) $round->crash_multiplier, (float) $round->acceleration_k);

                if (now()->getTimestampMs() / 1000 >= $round->started_at->getTimestamp() + $delay) {
                    $round = $this->rounds->crashRound($round);
                }
            }

            return $round;
        });

        return match ($round?->status) {
            JetRound::STATUS_CRASHED => $this->settlement->settle($round),
            JetRound::STATUS_VOIDING => $this->settlement->completeVoid($round),
            default => $round,
        };
    }

    private function leaseUntil(): string
    {
        return now()->addSeconds((int) config('jet.lease_seconds'))->format('Y-m-d H:i:s.u');
    }
}
