<?php

namespace App\Console\Commands;

use App\Domain\Jet\JetException;
use App\Domain\Jet\RoundRecoveryService;
use App\Domain\Jet\RoundScheduler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class JetRun extends Command
{
    /**
     * @var string
     */
    protected $signature = 'jet:run';

    /**
     * @var string
     */
    protected $description = 'Kadi Jet game loop: creates, runs and settles rounds forever. Run under supervisor (kadiapi-jet-loop), one process.';

    public function handle(RoundRecoveryService $recovery, RoundScheduler $scheduler): int
    {
        // After a restart the stopped loop's lease is still live for up to its lease time: wait it out instead of
        // failing, so supervisor never gives up on a quick exit
        $waitUntil = microtime(true) + (int) config('jet.lease_seconds') + 5;

        while (! $recovery->acquire()) {
            if (microtime(true) >= $waitUntil) {
                $this->error('Another Jet game loop holds the lease.');

                return self::FAILURE;
            }

            sleep(1);
        }

        $this->info('Jet game loop started.');

        while (true) {
            try {
                $round = $scheduler->runOnce();

                if ($round) {
                    $this->line("Round {$round->round_number} {$round->status} at {$round->crash_multiplier}x.");
                }
            } catch (JetException $e) {
                if ($e->errorCode === 'LOOP_LEASE_LOST') {
                    $this->error('Lost the Jet loop lease; exiting so supervisor restarts the loop.');

                    return self::FAILURE;
                }

                throw $e;
            } catch (Throwable $e) {
                Log::error('Jet game loop iteration failed', ['exception' => $e->getMessage()]);

                // A failed tick rolls back its own lease renewal: renew on its own so repeated errors never lapse it
                try {
                    $recovery->fenced(fn () => null);
                } catch (JetException) {
                    $this->error('Lost the Jet loop lease; exiting so supervisor restarts the loop.');

                    return self::FAILURE;
                }

                sleep(3);
            }
        }
    }
}
