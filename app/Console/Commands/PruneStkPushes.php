<?php

namespace App\Console\Commands;

use App\Models\StkPush;
use Illuminate\Console\Command;

class PruneStkPushes extends Command
{
    /**
     * @var string
     */
    protected $signature = 'stk-pushes:prune {--days=31 : Delete STK pushes recorded more than this many days ago}';

    /**
     * @var string
     */
    protected $description = 'Delete old STK push records (they only count against deposit limits for a few minutes)';

    public function handle(): int
    {
        $days = (int) $this->option('days');

        if ($days < 1) {
            $this->error('--days must be at least 1.');

            return self::FAILURE;
        }

        $deleted = StkPush::where('created_at', '<', now()->subDays($days))->delete();

        $this->info("Deleted {$deleted} STK pushes.");

        return self::SUCCESS;
    }
}
