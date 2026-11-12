<?php

namespace App\Console\Commands;

use App\Services\Predict\Polymarket\ReferencePriceRecorder;
use App\Services\Predict\Polymarket\Stream\MarketConnection;
use App\Services\Predict\Polymarket\Stream\PhrityMarketConnection;
use App\Services\Predict\Polymarket\Stream\PriceStreamWorker;
use Illuminate\Console\Command;

/**
 * Long-running: keep live imported markets priced from Polymarket's WebSocket market channel. Run it under
 * Supervisor; it exits after --max-seconds so Supervisor restarts it fresh.
 */
class PredictPriceStream extends Command
{
    /**
     * @var string
     */
    protected $signature = 'predict:price-stream {--max-seconds=3600 : Exit after this long (Supervisor restarts it)}';

    /**
     * @var string
     */
    protected $description = 'Stream Polymarket order books for live imported markets (WebSocket)';

    private bool $stopping = false;

    public function handle(ReferencePriceRecorder $recorder): int
    {
        if (! config('predict.polymarket.enabled')) {
            $this->info('Polymarket import is off (PREDICT_POLYMARKET_ENABLED); idling.');

            // Under Supervisor, exiting at once would mean a restart loop; idle a while first
            sleep((int) config('predict.polymarket.stream_idle_seconds'));

            return self::SUCCESS;
        }

        $this->trap([SIGTERM, SIGINT], fn () => $this->stopping = true);

        $connect = app()->bound(MarketConnection::class)
            ? fn () => app(MarketConnection::class)
            : fn () => new PhrityMarketConnection((string) config('predict.polymarket.stream_url'));

        $stats = (new PriceStreamWorker($connect, $recorder))->run((int) $this->option('max-seconds'), fn () => $this->stopping);

        $this->info(sprintf('Connections %d; messages %d; flushes %d.', $stats['connections'], $stats['messages'], $stats['flushes']));

        return self::SUCCESS;
    }
}
