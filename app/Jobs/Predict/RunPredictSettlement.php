<?php

namespace App\Jobs\Predict;

use App\Models\PredictSettlement;
use App\Services\Predict\SettlementService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Pay out or refund one prediction market. Safe to run again: see SettlementService.
 */
class RunPredictSettlement implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $uniqueFor = 600;

    public function __construct(public int $settlementId, public ?string $voidReason = null)
    {
        $this->onQueue(config('predict.queue'));
    }

    public function uniqueId(): string
    {
        return (string) $this->settlementId;
    }

    public function handle(SettlementService $settlements): void
    {
        $run = PredictSettlement::find($this->settlementId);

        if ($run) {
            $settlements->run($run, $this->voidReason);
        }
    }
}
