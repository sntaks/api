<?php

namespace App\Console\Commands;

use App\Models\LaunchToken;
use Illuminate\Console\Command;

class PruneLaunchTokens extends Command
{
    /**
     * @var string
     */
    protected $signature = 'launch-tokens:prune {--hours=24 : Delete launch tokens created more than this many hours ago}';

    /**
     * @var string
     */
    protected $description = 'Delete old launch tokens (each lives 120 seconds; the rows are only kept for a day of audit)';

    public function handle(): int
    {
        $deleted = LaunchToken::where('created_at', '<', now()->subHours((int) $this->option('hours')))->delete();

        $this->info("Deleted {$deleted} launch tokens.");

        return self::SUCCESS;
    }
}
