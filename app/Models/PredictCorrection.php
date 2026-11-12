<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A change of a settled market's result, approved by a second admin and applied wager by wager.
 */
class PredictCorrection extends Model
{
    public const STATUS_REQUESTED = 'requested';

    public const STATUS_RUNNING = 'running';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'predict_market_id',
        'from_result',
        'to_result',
        'reason',
        'status',
        'requested_by',
        'approved_by',
        'approved_at',
        'wagers_corrected',
        'attempts',
        'error',
        'finished_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'finished_at' => 'datetime',
            'wagers_corrected' => 'integer',
            'attempts' => 'integer',
        ];
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(PredictMarket::class, 'predict_market_id');
    }
}
