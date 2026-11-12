<?php

namespace App\Services\Predict;

use App\Models\PredictResolutionProposal;
use Illuminate\Support\Facades\DB;

/**
 * An admin confirms (which resolves the market and pays out) or rejects a Polymarket result proposal.
 */
class ResolutionService
{
    public function __construct(private MarketService $markets, private AuditLogger $audit) {}

    public function confirm(PredictResolutionProposal $proposal, string $adminUser, ?string $reason): PredictResult
    {
        return DB::transaction(function () use ($proposal, $adminUser, $reason) {
            $locked = PredictResolutionProposal::whereKey($proposal->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== PredictResolutionProposal::STATUS_PENDING) {
                return PredictResult::refused('proposal_not_pending', 'This proposal was already reviewed', 409, ['status' => $locked->status]);
            }

            $resolved = $this->markets->resolve($locked->market, $locked->result, $adminUser, $reason ?? 'Polymarket result confirmed');

            if ($resolved->isRefused()) {
                return $resolved;
            }

            $locked->update(['status' => PredictResolutionProposal::STATUS_CONFIRMED, 'reviewed_by' => $adminUser, 'review_reason' => $reason, 'reviewed_at' => now()]);

            return PredictResult::ok(['proposal' => $locked, 'market' => $resolved->data['market']]);
        });
    }

    /**
     * Turn a proposal down. The sync will not propose the same result for that market again; an admin can still
     * resolve it by hand.
     */
    public function reject(PredictResolutionProposal $proposal, string $adminUser, string $reason): PredictResult
    {
        return DB::transaction(function () use ($proposal, $adminUser, $reason) {
            $locked = PredictResolutionProposal::whereKey($proposal->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== PredictResolutionProposal::STATUS_PENDING) {
                return PredictResult::refused('proposal_not_pending', 'This proposal was already reviewed', 409, ['status' => $locked->status]);
            }

            $locked->update(['status' => PredictResolutionProposal::STATUS_REJECTED, 'reviewed_by' => $adminUser, 'review_reason' => $reason, 'reviewed_at' => now()]);
            $this->audit->record('reject_proposal', $locked->market, $adminUser, $reason, null, ['proposal_id' => $locked->id, 'result' => $locked->result]);

            return PredictResult::ok(['proposal' => $locked, 'market' => $locked->market]);
        });
    }
}
