<?php

namespace App\Console\Commands;

use App\Services\Stats\StatsRollupService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class StatsRebuild extends Command
{
    protected $signature = 'stats:rebuild
        {--from= : First day to rebuild (Y-m-d or "yesterday", Nairobi), default today}
        {--to= : Last day to rebuild, default the --from day}';

    protected $description = 'Rebuild the stats dashboard daily rollups from the ledger and wagers (idempotent)';

    public function handle(StatsRollupService $rollups): int
    {
        $timezone = config('app.timezone');

        try {
            $from = CarbonImmutable::parse($this->option('from') ?? 'today', $timezone)->startOfDay();
            $to = CarbonImmutable::parse($this->option('to') ?? $from->toDateString(), $timezone)->startOfDay();
        } catch (\Throwable) {
            $this->error('--from and --to must be dates (Y-m-d).');

            return self::FAILURE;
        }

        if ($to->lessThan($from)) {
            $this->error('--to must be on or after --from.');

            return self::FAILURE;
        }

        $days = $rollups->rebuildRange($from, $to, fn (CarbonImmutable $day) => $this->line("Rebuilt {$day->toDateString()}", null, 'v'));

        $this->info("Rebuilt {$days} day(s) from {$from->toDateString()} to {$to->toDateString()}.");

        return self::SUCCESS;
    }
}
