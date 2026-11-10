<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer an admin barred from betting on one market.
 */
class PredictMarketBlock extends Model
{
    protected $fillable = [
        'predict_market_id',
        'customer_id',
        'reason',
        'admin_user',
    ];

    public function market(): BelongsTo
    {
        return $this->belongsTo(PredictMarket::class, 'predict_market_id');
    }
}
