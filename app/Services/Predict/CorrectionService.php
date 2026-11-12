<?php

namespace App\Services\Predict;

use App\Jobs\Predict\RunPredictCorrection;
use App\Models\PredictBet;
use App\Models\PredictCorrection;
use App\Models\PredictMarket;
use App\Models\Wager;
use App\Services\Predict\Engines\EngineRegistry;
use App\Services\WagerService;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Corrects the result of a settled market. One admin requests it, a different admin approves it, then a job
 * moves each wager to the new result: reverse its current settlement and settle it again at the next version
 * (stake, payout and tax legs exactly as the wager API does). A wager already holding the new result is left
 * alone, so an interrupted run is simply run again. A player paid wrongly may be left with a negative balance,
 * recovered from later deposits (decided 2026-11-10).
 */
class CorrectionService
{
    public function __construct(
        private PredictContext $context,
        private EngineRegistry $engines,
        private WagerService $wagers,
        private AuditLogger $audit,
    ) {}

    public function request(PredictMarket $market, string $toResult, string $adminUser, string $reason): PredictResult
    {
        return DB::transaction(function () use ($market, $toResult, $adminUser, $reason) {
            $locked = PredictMarket::whereKey($market->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== PredictMarket::STATUS_SETTLED) {
                return PredictResult::refused('market_not_settled', 'Only a settled market can be corrected', 409, ['status' => $locked->status]);
            }

            if ($locked->result === $toResult) {
                return PredictResult::refused('same_result', 'The market already has that result', 409);
            }

            if ($locked->corrections()->whereIn('status', [PredictCorrection::STATUS_REQUESTED, PredictCorrection::STATUS_RUNNING, PredictCorrection::STATUS_FAILED])->exists()) {
                return PredictResult::refused('correction_open', 'This market already has a correction in progress', 409);
            }

            $correction = PredictCorrection::create([
                'predict_market_id' => $locked->id,
                'from_result' => $locked->result,
                'to_result' => $toResult,
                'reason' => $reason,
                'status' => PredictCorrection::STATUS_REQUESTED,
                'requested_by' => $adminUser,
            ]);

            $this->audit->record('request_correction', $locked, $adminUser, $reason, ['result' => $locked->result], ['result' => $toResult, 'correction_id' => $correction->id]);

            return PredictResult::ok(['correction' => $correction], created: true);
        });
    }

    /**
     * A second admin approves; the correction then runs on the queue.
     */
    public function approve(PredictCorrection $correction, string $adminUser, ?string $reason): PredictResult
    {
        return DB::transaction(function () use ($correction, $adminUser, $reason) {
            $locked = PredictCorrection::whereKey($correction->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== PredictCorrection::STATUS_REQUESTED) {
                return PredictResult::refused('correction_not_requested', 'Only a requested correction can be approved', 409, ['status' => $locked->status]);
            }

            if (strcasecmp($locked->requested_by, $adminUser) === 0) {
                return PredictResult::refused('same_admin', 'A different admin must approve the correction', 403);
            }

            $locked->update(['status' => PredictCorrection::STATUS_RUNNING, 'approved_by' => $adminUser, 'approved_at' => now()]);
            $this->audit->record('approve_correction', $locked->market, $adminUser, $reason, null, ['correction_id' => $locked->id, 'result' => $locked->to_result]);
            RunPredictCorrection::dispatch($locked->id)->afterCommit();

            return PredictResult::ok(['correction' => $locked]);
        });
    }

    public function cancel(PredictCorrection $correction, string $adminUser, string $reason): PredictResult
    {
        return DB::transaction(function () use ($correction, $adminUser, $reason) {
            $locked = PredictCorrection::whereKey($correction->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== PredictCorrection::STATUS_REQUESTED) {
                return PredictResult::refused('correction_not_requested', 'Only a requested correction can be cancelled', 409, ['status' => $locked->status]);
            }

            $locked->update(['status' => PredictCorrection::STATUS_CANCELLED]);
            $this->audit->record('cancel_correction', $locked->market, $adminUser, $reason, null, ['correction_id' => $locked->id]);

            return PredictResult::ok(['correction' => $locked]);
        });
    }

    /**
     * Queue a failed correction again (it was approved already). Safe: corrected wagers are left alone.
     */
    public function retry(PredictCorrection $correction, string $adminUser): PredictResult
    {
        if ($correction->status !== PredictCorrection::STATUS_FAILED) {
            return PredictResult::refused('correction_not_failed', 'Only a failed correction can be retried', 409, ['status' => $correction->status]);
        }

        $correction->update(['status' => PredictCorrection::STATUS_RUNNING, 'error' => null]);
        $this->audit->record('retry_correction', $correction->market, $adminUser, null, null, ['correction_id' => $correction->id]);
        RunPredictCorrection::dispatch($correction->id);

        return PredictResult::ok(['correction' => $correction]);
    }

    /**
     * Apply an approved correction. Marks it failed, with the reason, instead of throwing.
     */
    public function run(PredictCorrection $correction): PredictCorrection
    {
        if ($correction->status !== PredictCorrection::STATUS_RUNNING) {
            return $correction;
        }

        $correction->increment('attempts');
        $market = $correction->market()->with('product')->firstOrFail();

        try {
            $error = $this->applyToWagers($market, $correction);
        } catch (Throwable $e) {
            report($e);
            $error = $e->getMessage();
        }

        if ($error !== null) {
            $correction->update(['status' => PredictCorrection::STATUS_FAILED, 'error' => $error]);

            return $correction;
        }

        DB::transaction(function () use ($market, $correction) {
            $market->update(['result' => $correction->to_result, 'resolved_by' => $correction->approved_by, 'resolved_at' => now()]);
            $this->syncBets($market, $correction);
            $correction->update(['status' => PredictCorrection::STATUS_DONE, 'finished_at' => now()]);
            $this->audit->record('correct', $market, (string) $correction->approved_by, $correction->reason, ['result' => $correction->from_result], ['result' => $correction->to_result]);
        });

        return $correction->fresh();
    }

    /**
     * Move every wager of the market to the new result. Null when all went through.
     */
    private function applyToWagers(PredictMarket $market, PredictCorrection $correction): ?string
    {
        $plan = $this->engines->for($market)->settle($market, $correction->to_result);
        $payouts = collect($plan->winners)->mapWithKeys(fn (array $winner) => [$winner['reference'] => $winner['payout_minor']]);
        $key = $this->context->wagerKey();
        $product = $market->product->code;
        $reason = "Correction #{$correction->id}: result {$correction->to_result}";
        $corrected = 0;

        $wagers = Wager::whereIn('id', PredictBet::where('predict_market_id', $market->id)->select('wager_id'))
            ->where('status', '!=', Wager::STATUS_VOID)
            ->orderBy('id')
            ->get();

        foreach ($wagers as $wager) {
            $payoutMinor = $payouts[$wager->reference] ?? 0;
            $outcome = $payoutMinor > 0 ? Wager::STATUS_WON : Wager::STATUS_LOST;

            if ($wager->status === $outcome && (int) round(((float) $wager->payout) * 100) === $payoutMinor) {
                continue;
            }

            if ($wager->status !== Wager::STATUS_OPEN) {
                $reversed = $this->wagers->reverse($key, $product, $wager->reference, ['version' => $wager->settlement_version, 'reason' => $reason]);

                if ($reversed->isRefused()) {
                    return "Wager {$wager->reference}: ".$reversed->refusal['code'];
                }
            }

            $settled = $this->wagers->settle($key, $product, $wager->reference, [
                // A reversal keeps the version and reopens the wager, so the new settlement is always the next one
                'version' => $wager->settlement_version + 1,
                'outcome' => $outcome,
                'payout' => bcdiv((string) $payoutMinor, '100', 2),
                'reason' => $reason,
            ]);

            if ($settled->isRefused()) {
                return "Wager {$wager->reference}: ".$settled->refusal['code'];
            }

            $correction->update(['wagers_corrected' => ++$corrected]);
        }

        return null;
    }

    /**
     * Copy each wager's new outcome and payout onto its bet; a pool's bets take the new final multiplier.
     */
    private function syncBets(PredictMarket $market, PredictCorrection $correction): void
    {
        $poolMultiplier = $market->engine === PredictMarket::ENGINE_POOL
            ? $this->engines->for($market)->settle($market, $correction->to_result)->finalMultiplierX100
            : null;

        PredictBet::where('predict_market_id', $market->id)->with('wager:id,status,payout,settled_at')->chunkById(500, function ($bets) use ($poolMultiplier) {
            foreach ($bets as $bet) {
                $bet->update([
                    'status' => $bet->wager->status,
                    'payout_minor' => (int) round(((float) $bet->wager->payout) * 100),
                    'final_multiplier_x100' => $poolMultiplier ?? $bet->estimated_multiplier_x100,
                    'settled_at' => $bet->wager->settled_at ?? now(),
                ]);
            }
        });
    }
}
