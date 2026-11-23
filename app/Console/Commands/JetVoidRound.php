<?php

namespace App\Console\Commands;

use App\Domain\Jet\JetException;
use App\Domain\Jet\SettlementService;
use App\Models\JetRound;
use Illuminate\Console\Command;

class JetVoidRound extends Command
{
    /**
     * @var string
     */
    protected $signature = 'jet:void-round {round : The round number} {--reason= : Why (kept on the round and the wagers)} {--by= : Who decided it}';

    /**
     * @var string
     */
    protected $description = 'Void a broken Kadi Jet round that is not settled: refunds every open bet; bets already cashed out keep their win';

    public function handle(SettlementService $settlement): int
    {
        $reason = trim((string) $this->option('reason'));
        $by = trim((string) $this->option('by'));

        if ($reason === '' || $by === '') {
            $this->error('--reason and --by are required.');

            return self::FAILURE;
        }

        $round = JetRound::where('round_number', (int) $this->argument('round'))->first();

        if (! $round) {
            $this->error('No such round.');

            return self::FAILURE;
        }

        try {
            $round = $settlement->void($round, $reason, "console:{$by}");
        } catch (JetException $e) {
            $this->error("Refused: {$e->errorCode}");

            return self::FAILURE;
        }

        $this->info("Round {$round->round_number} is {$round->status}.");

        return self::SUCCESS;
    }
}
