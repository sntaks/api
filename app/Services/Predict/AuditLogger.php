<?php

namespace App\Services\Predict;

use App\Models\PredictAuditLog;
use App\Models\PredictMarket;
use Illuminate\Http\Request;

/**
 * Writes the append-only admin audit log for prediction markets.
 */
class AuditLogger
{
    public function __construct(private ?Request $request = null) {}

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(string $action, ?PredictMarket $market, string $adminUser, ?string $reason = null, ?array $before = null, ?array $after = null): PredictAuditLog
    {
        return PredictAuditLog::create([
            'predict_market_id' => $market?->id,
            'action' => $action,
            'admin_user' => $adminUser,
            'reason' => $reason,
            'before' => $before,
            'after' => $after,
            'api_key_id' => $this->request?->attributes->get('api_key')?->id,
            'ip' => $this->request?->ip(),
        ]);
    }
}
