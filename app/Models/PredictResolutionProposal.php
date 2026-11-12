<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A result Polymarket reported for an imported market, waiting for an admin.
 */
class PredictResolutionProposal extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'predict_market_id',
        'result',
        'evidence',
        'status',
        'reviewed_by',
        'review_reason',
        'reviewed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'evidence' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(PredictMarket::class, 'predict_market_id');
    }
}
