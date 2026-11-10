<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A pool market's running totals in minor units, the house seed included.
 */
class PredictPool extends Model
{
    protected $primaryKey = 'predict_market_id';

    public $incrementing = false;

    protected $fillable = [
        'predict_market_id',
        'yes_minor',
        'no_minor',
        'seed_yes_minor',
        'seed_no_minor',
        'bets_count',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'yes_minor' => 'integer',
            'no_minor' => 'integer',
            'seed_yes_minor' => 'integer',
            'seed_no_minor' => 'integer',
            'bets_count' => 'integer',
        ];
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(PredictMarket::class, 'predict_market_id');
    }
}
