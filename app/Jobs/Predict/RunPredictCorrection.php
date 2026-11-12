<?php

namespace App\Jobs\Predict;

use App\Models\PredictCorrection;
use App\Services\Predict\CorrectionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Apply an approved result correction. Safe to run again: see CorrectionService.
 */
class RunPredictCorrection implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $uniqueFor = 600;

    public function __construct(public int $correctionId)
    {
        $this->onQueue(config('predict.queue'));
    }

    public function uniqueId(): string
    {
        return (string) $this->correctionId;
    }

    public function handle(CorrectionService $corrections): void
    {
        $correction = PredictCorrection::find($this->correctionId);

        if ($correction) {
            $corrections->run($correction);
        }
    }
}
