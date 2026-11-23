<?php

namespace App\Domain\Jet;

use App\Models\JetRound;
use RuntimeException;

/**
 * Round lifecycle: scheduled → betting → running → crashed (then SettlementService settles it). Everything about
 * a round, the crash point included, is fixed at creation and the server seed stays hidden until settlement.
 */
class RoundService
{
    public function __construct(private ProvablyFairService $provablyFair, private JetSettings $settings) {}

    public function createRound(): JetRound
    {
        return JetLock::run(function () {
            $houseEdge = (float) config('jet.house_edge');
            $cap = (float) $this->settings->current()['max_multiplier'];
            $k = (float) config('jet.acceleration_k');

            if (! is_finite($k) || $k <= 0 || ! is_finite($houseEdge) || $houseEdge < 0 || $houseEdge >= 1 || ! is_finite($cap) || $cap < 1) {
                throw new RuntimeException('Invalid Kadi Jet round policy');
            }

            if (JetRound::whereNotIn('status', JetRound::FINISHED)->exists()) {
                throw new JetException('ROUND_IN_PROGRESS', 409, 'backoff');
            }

            $serverSeed = $this->provablyFair->generateServerSeed();
            $clientSeed = $this->provablyFair->generateClientSeed();
            $roundNumber = (int) JetRound::max('round_number') + 1;

            $round = JetRound::create([
                'round_number' => $roundNumber,
                'status' => JetRound::STATUS_SCHEDULED,
                'revision' => 1,
                'server_seed' => $serverSeed,
                'server_seed_hash' => $this->provablyFair->hashServerSeed($serverSeed),
                'client_seed' => $clientSeed,
                'nonce' => $roundNumber,
                'house_edge' => $houseEdge,
                'max_multiplier_cap' => $cap,
                'acceleration_k' => $k,
                'crash_multiplier' => $this->provablyFair->crashPoint($serverSeed, $clientSeed, $roundNumber, $houseEdge, $cap),
            ]);

            EventPublisher::changed();

            return $round;
        });
    }

    public function openBetting(JetRound $round): JetRound
    {
        return $this->transition($round, JetRound::STATUS_SCHEDULED, fn () => [
            'status' => JetRound::STATUS_BETTING,
            'betting_closes_at' => now()->addSeconds($this->settings->current()['betting_window_seconds']),
        ]);
    }

    /**
     * An overdue round starts at its original close deadline, so recovery never stretches the window.
     */
    public function startRound(JetRound $round): JetRound
    {
        return $this->transition($round, JetRound::STATUS_BETTING, fn (JetRound $locked) => [
            'status' => JetRound::STATUS_RUNNING,
            'started_at' => $locked->betting_closes_at && $locked->betting_closes_at->isPast() ? $locked->betting_closes_at : now()->startOfSecond(),
        ]);
    }

    public function crashRound(JetRound $round): JetRound
    {
        return $this->transition($round, JetRound::STATUS_RUNNING, fn () => [
            'status' => JetRound::STATUS_CRASHED,
            'crashed_at' => now(),
        ]);
    }

    /**
     * @param  callable(JetRound): array<string, mixed>  $changes
     */
    private function transition(JetRound $round, string $from, callable $changes): JetRound
    {
        return JetLock::run(function () use ($round, $from, $changes) {
            $locked = JetRound::whereKey($round->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== $from) {
                throw new RuntimeException("Jet round {$locked->round_number} is {$locked->status}, expected {$from}");
            }

            $locked->forceFill([...$changes($locked), 'revision' => $locked->revision + 1])->save();
            EventPublisher::changed();

            return $locked;
        });
    }
}
