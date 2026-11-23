<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class JetPrune extends Command
{
    /**
     * @var string
     */
    protected $signature = 'jet:prune';

    /**
     * @var string
     */
    protected $description = 'Drop expired Kadi Jet operation results (keeping the keys) and delivered outbox rows and day-old launch codes';

    public function handle(): int
    {
        $cutoff = now()->subDays((int) config('jet.operation_ttl_days'));

        DB::table('jet_operations')->where('created_at', '<', $cutoff)->whereNotNull('result')->update(['result' => null]);
        DB::table('jet_session_codes')->where('expires_at', '<', now()->subDay())->delete();
        DB::table('jet_outbox')->whereNotNull('published_at')->where('published_at', '<', $cutoff)->delete();

        return self::SUCCESS;
    }
}
