<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An LMSR market's outstanding shares per side and its liquidity b (KES, decimal strings).
 */
class PredictLmsrState extends Model
{
    protected $primaryKey = 'predict_market_id';

    public $incrementing = false;

    protected $fillable = [
        'predict_market_id',
        'q_yes',
        'q_no',
        'liquidity',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'q_yes' => 'decimal:6',
            'q_no' => 'decimal:6',
            'liquidity' => 'decimal:2',
        ];
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(PredictMarket::class, 'predict_market_id');
    }
}
