<?php

namespace App\Console\Commands;

use App\Domain\Jet\RoundRecoveryService;
use Illuminate\Console\Command;

class JetSettleExpired extends Command
{
    /**
     * @var string
     */
    protected $signature = 'jet:settle-expired';

    /**
     * @var string
     */
    protected $description = 'Move an abandoned Kadi Jet round on from its saved deadlines (settle or finish a void). Yields to a healthy game loop.';

    public function handle(RoundRecoveryService $recovery): int
    {
        if (! $recovery->acquire()) {
            return self::SUCCESS;
        }

        try {
            $recovery->tick();
        } finally {
            $recovery->release();
        }

        return self::SUCCESS;
    }
}
