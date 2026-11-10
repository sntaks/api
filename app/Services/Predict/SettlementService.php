<?php

namespace App\Services\Predict;

use App\Jobs\Predict\RunPredictSettlement;
use App\Models\PredictBet;
use App\Models\PredictMarket;
use App\Models\PredictSettlement;
use App\Models\Wager;
use App\Services\Predict\Engines\EngineRegistry;
use App\Services\WagerRoundService;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Pays out or refunds a market through its wager round. A run is resumable: the round only touches open,
 * never-settled wagers and the engine's plan is deterministic from the frozen pool, so rerunning a failed or
 * interrupted run never pays anyone twice.
 */
class SettlementService
{
    public function __construct(
        private PredictContext $context,
        private EngineRegistry $engines,
        private WagerRoundService $rounds,
    ) {}

    /**
     * Inside the resolve transaction: record the run and queue it once that transaction commits.
     */
    public function start(PredictMarket $market, string $adminUser): PredictSettlement
    {
        return $this->newRun($market, PredictSettlement::KIND_SETTLE, $market->result, $adminUser);
    }

    /**
     * Inside the void transaction: record a refund run and queue it once that transaction commits.
     */
    public function startVoid(PredictMarket $market, string $adminUser, string $reason): PredictSettlement
    {
        return $this->newRun($market, PredictSettlement::KIND_VOID, null, $adminUser, $reason);
    }

    /** A run still `running` after this long is taken as stuck (its worker died) and may be retried */
    public const STUCK_AFTER_MINUTES = 15;

    /**
     * Queue a failed or stuck run again.
     */
    public function retry(PredictSettlement $run): PredictResult
    {
        $isStuck = $run->status === PredictSettlement::STATUS_RUNNING && $run->updated_at->lt(now()->subMinutes(self::STUCK_AFTER_MINUTES));

        if ($run->status !== PredictSettlement::STATUS_FAILED && ! $isStuck) {
            return PredictResult::refused('settlement_not_retryable', 'Only a failed run, or one running for over '.self::STUCK_AFTER_MINUTES.' minutes, can be retried', 409, ['status' => $run->status]);
        }

        $run->update(['status' => PredictSettlement::STATUS_RUNNING, 'error' => null]);
        RunPredictSettlement::dispatch($run->id);

        return PredictResult::ok(['settlement' => $run]);
    }

    /**
     * Run (or rerun) a settlement. Marks the run failed, with the reason, instead of throwing.
     */
    public function run(PredictSettlement $run, ?string $voidReason = null): PredictSettlement
    {
        if ($run->status === PredictSettlement::STATUS_DONE) {
            return $run;
        }

        $run->increment('attempts');
        $market = $run->market()->with('product')->firstOrFail();

        try {
            $refusal = $run->kind === PredictSettlement::KIND_SETTLE
                ? $this->settleRound($market, $run)
                : $this->voidRound($market, $voidReason ?? $market->void_reason);
        } catch (Throwable $e) {
            report($e);
            $refusal = $e->getMessage();
        }

        if ($refusal !== null) {
            $run->update(['status' => PredictSettlement::STATUS_FAILED, 'error' => $refusal]);

            return $run;
        }

        DB::transaction(function () use ($market, $run) {
            $this->syncBets($market, $run);

            $totals = PredictBet::where('predict_market_id', $market->id)
                ->where('status', '!=', PredictBet::STATUS_VOID)
                ->selectRaw('COALESCE(SUM(stake_minor), 0) AS stakes, COALESCE(SUM(payout_minor), 0) AS payouts')
                ->first();

            $run->update([
                'status' => PredictSettlement::STATUS_DONE,
                'winners_count' => PredictBet::where('predict_market_id', $market->id)->where('status', PredictBet::STATUS_WON)->count(),
                'user_stake_minor' => (int) $totals->stakes,
                'payout_minor' => (int) $totals->payouts,
                'house_net_minor' => (int) $totals->stakes - (int) $totals->payouts,
                'finished_at' => now(),
            ]);

            if ($run->kind === PredictSettlement::KIND_SETTLE) {
                PredictMarket::whereKey($market->id)->where('status', PredictMarket::STATUS_RESOLVED)
                    ->update(['status' => PredictMarket::STATUS_SETTLED, 'settled_at' => now()]);
            }
        });

        return $run->fresh();
    }

    private function newRun(PredictMarket $market, string $kind, ?string $result, string $adminUser, ?string $voidReason = null): PredictSettlement
    {
        $pool = $market->pool()->first();

        $run = PredictSettlement::create([
            'predict_market_id' => $market->id,
            'run_no' => (int) PredictSettlement::where('predict_market_id', $market->id)->max('run_no') + 1,
            'kind' => $kind,
            'result' => $result,
            'status' => PredictSettlement::STATUS_RUNNING,
            'yes_minor' => $pool?->yes_minor ?? 0,
            'no_minor' => $pool?->no_minor ?? 0,
            'admin_user' => $adminUser,
        ]);

        RunPredictSettlement::dispatch($run->id, $voidReason)->afterCommit();

        return $run;
    }

    /**
     * Pay the plan's winners and settle every other open wager of the round as lost. Null when it went through.
     */
    private function settleRound(PredictMarket $market, PredictSettlement $run): ?string
    {
        $plan = $this->engines->for($market)->settle($market, $market->result);
        $run->update(['final_multiplier_x100' => $plan->finalMultiplierX100]);

        $winners = array_values(array_map(fn (array $winner) => [
            'reference' => $winner['reference'],
            'payout' => $this->kes($winner['payout_minor']),
        ], $plan->winners));

        $result = $this->rounds->settle($this->context->wagerKey(), $market->product->code, $market->round_reference, $winners, true);

        return $result->isRefused() ? $result->refusal['code'].': '.$result->refusal['message'] : null;
    }

    private function voidRound(PredictMarket $market, ?string $reason): ?string
    {
        $result = $this->rounds->void($this->context->wagerKey(), $market->product->code, $market->round_reference, $reason);

        return $result->isRefused() ? $result->refusal['code'].': '.$result->refusal['message'] : null;
    }

    /**
     * Copy each wager's outcome and payout onto its bet. The wager is the source of truth for money.
     */
    private function syncBets(PredictMarket $market, PredictSettlement $run): void
    {
        PredictBet::where('predict_market_id', $market->id)
            ->where('status', PredictBet::STATUS_OPEN)
            ->with('wager:id,status,payout,settled_at')
            ->chunkById(500, function ($bets) use ($run) {
                foreach ($bets as $bet) {
                    if ($bet->wager->status === Wager::STATUS_OPEN) {
                        continue;
                    }

                    $bet->update([
                        'status' => $bet->wager->status,
                        'payout_minor' => (int) round(((float) $bet->wager->payout) * 100),
                        'final_multiplier_x100' => $run->final_multiplier_x100,
                        'settled_at' => $bet->wager->settled_at ?? now(),
                    ]);
                }
            });
    }

    private function kes(int $minor): string
    {
        return bcdiv((string) $minor, '100', 2);
    }
}
