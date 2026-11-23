<?php

namespace App\Console\Commands;

use App\Domain\Jet\EventPublisher;
use Illuminate\Console\Command;

class JetPublishOutbox extends Command
{
    /**
     * @var string
     */
    protected $signature = 'jet:publish-outbox';

    /**
     * @var string
     */
    protected $description = 'Queue pending Kadi Jet snapshot invalidations for broadcast (the game loop also does this every tick)';

    public function handle(EventPublisher $publisher): int
    {
        $publisher->flush();

        return self::SUCCESS;
    }
}
