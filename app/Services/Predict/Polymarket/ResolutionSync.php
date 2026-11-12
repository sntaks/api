<?php

namespace App\Services\Predict\Polymarket;

use App\Models\PredictMarket;
use App\Models\PredictResolutionProposal;
use App\Services\Predict\MarketService;
use App\Services\Predict\ResolutionService;

/**
 * Proposes results for imported markets once Polymarket has resolved them (closed, UMA status resolved, final
 * prices exactly 1 and 0). The market is closed first if it is still open. An admin confirms each proposal,
 * unless `predict.resolution.auto_settle` is on. A market with a pending or confirmed proposal, or a rejected one
 * for the same result, is not proposed again.
 */
class ResolutionSync
{
    public function __construct(
        private GammaClient $gamma,
        private MarketMapper $mapper,
        private MarketService $markets,
        private ResolutionService $resolutions,
    ) {}

    /**
     * @return array{checked: int, proposed: int, confirmed: int}
     */
    public function sync(): array
    {
        $candidates = PredictMarket::where('source', PredictMarket::SOURCE_POLYMARKET)
            ->whereIn('status', [PredictMarket::STATUS_OPEN, PredictMarket::STATUS_SUSPENDED, PredictMarket::STATUS_CLOSED])
            ->where(fn ($q) => $q->where('close_at', '<=', now())->orWhere('external_closed', true))
            ->whereDoesntHave('proposals', fn ($q) => $q->whereIn('status', [PredictResolutionProposal::STATUS_PENDING, PredictResolutionProposal::STATUS_CONFIRMED]))
            ->get()
            ->keyBy('external_market_id');

        $counts = ['checked' => $candidates->count(), 'proposed' => 0, 'confirmed' => 0];

        if ($candidates->isEmpty()) {
            return $counts;
        }

        foreach ($this->gamma->marketsById($candidates->keys()->map(fn ($id) => (string) $id)->all()) as $raw) {
            $imported = $this->mapper->map($raw);
            $market = $imported ? $candidates->get($imported->externalId) : null;
            $result = $imported?->resolvedSide();

            if (! $market || $result === null || $market->proposals()->where('status', PredictResolutionProposal::STATUS_REJECTED)->where('result', $result)->exists()) {
                continue;
            }

            if (in_array($market->status, [PredictMarket::STATUS_OPEN, PredictMarket::STATUS_SUSPENDED], true)) {
                $this->markets->close($market, 'polymarket-sync', 'Resolved on Polymarket');
            }

            $proposal = PredictResolutionProposal::create([
                'predict_market_id' => $market->id,
                'result' => $result,
                'status' => PredictResolutionProposal::STATUS_PENDING,
                'evidence' => [
                    'uma_resolution_status' => $raw['umaResolutionStatus'] ?? null,
                    'outcome_prices' => $raw['outcomePrices'] ?? null,
                    'outcomes' => $raw['outcomes'] ?? null,
                    'closed_time' => $raw['closedTime'] ?? null,
                    'automatically_resolved' => $raw['automaticallyResolved'] ?? null,
                ],
            ]);
            $counts['proposed']++;

            if (config('predict.resolution.auto_settle')) {
                $counts['confirmed'] += $this->resolutions->confirm($proposal, 'polymarket-sync', 'Auto-settle')->isRefused() ? 0 : 1;
            }
        }

        return $counts;
    }
}
