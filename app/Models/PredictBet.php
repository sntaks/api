<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A player's bet on one side of a market; the money is on the linked wager.
 */
class PredictBet extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_WON = 'won';

    public const STATUS_LOST = 'lost';

    public const STATUS_VOID = 'void';

    protected $fillable = [
        'predict_market_id',
        'wager_id',
        'customer_id',
        'reference',
        'side',
        'currency',
        'stake_minor',
        'estimated_multiplier_x100',
        'final_multiplier_x100',
        'payout_minor',
        'status',
        'placed_at',
        'settled_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stake_minor' => 'integer',
            'estimated_multiplier_x100' => 'integer',
            'final_multiplier_x100' => 'integer',
            'payout_minor' => 'integer',
            'placed_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(PredictMarket::class, 'predict_market_id');
    }

    public function wager(): BelongsTo
    {
        return $this->belongsTo(Wager::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }
}
