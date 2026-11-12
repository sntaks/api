<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A fixed-odds price offered to one customer for one stake: valid until `expires_at`, usable once.
 */
class PredictQuote extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'predict_market_id',
        'customer_id',
        'side',
        'stake_minor',
        'p_fair',
        'p_offer',
        'multiplier_x100',
        'expires_at',
        'used_at',
        'predict_bet_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stake_minor' => 'integer',
            'p_fair' => 'decimal:6',
            'p_offer' => 'decimal:6',
            'multiplier_x100' => 'integer',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(PredictMarket::class, 'predict_market_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
