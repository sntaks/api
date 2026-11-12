<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The YES or NO side of an imported market: its Polymarket token and the current reference price.
 */
class PredictOutcome extends Model
{
    protected $fillable = [
        'predict_market_id',
        'side',
        'label',
        'external_token_id',
        'midpoint',
        'price_reliable',
        'price_captured_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'midpoint' => 'decimal:6',
            'price_reliable' => 'boolean',
            'price_captured_at' => 'datetime',
        ];
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(PredictMarket::class, 'predict_market_id');
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(PredictPriceSnapshot::class);
    }

    /**
     * A price is usable while it is reliable and younger than `predict.polymarket.stale_after_seconds`.
     */
    public function hasUsablePrice(): bool
    {
        return $this->price_reliable
            && $this->midpoint !== null
            && $this->price_captured_at !== null
            && $this->price_captured_at->gt(now()->subSeconds((int) config('predict.polymarket.stale_after_seconds')));
    }
}
