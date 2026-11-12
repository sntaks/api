<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A fixed-odds market's totals for one side: stakes taken on it and payouts owed if it wins. Composite key
 * (market, side): always query by both columns, never save through the model.
 */
class PredictExposure extends Model
{
    public $incrementing = false;

    protected $primaryKey = null;

    protected $fillable = [
        'predict_market_id',
        'side',
        'stakes_minor',
        'payouts_minor',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stakes_minor' => 'integer',
            'payouts_minor' => 'integer',
        ];
    }

    /**
     * @param  Builder<PredictExposure>  $query
     */
    public function scopeForMarket(Builder $query, int $marketId): void
    {
        $query->where('predict_market_id', $marketId);
    }
}
