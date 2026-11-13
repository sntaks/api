<?php

namespace App\Models;

use App\Services\Predict\Polymarket\Stream\PriceStream;
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
        'best_bid',
        'best_ask',
        'spread',
        'depth_usd',
        'price_reliable',
        'price_captured_at',
        'price_changed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'midpoint' => 'decimal:6',
            'best_bid' => 'decimal:6',
            'best_ask' => 'decimal:6',
            'spread' => 'decimal:6',
            'depth_usd' => 'decimal:2',
            'price_reliable' => 'boolean',
            'price_captured_at' => 'datetime',
            'price_changed_at' => 'datetime',
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
     * How old a price may be to bet on: stricter while the market stream is live (prices arrive within a second),
     * looser on REST polling alone.
     */
    public static function maxBettingAgeSeconds(): int
    {
        return (int) (PriceStream::isLive() ? config('predict.fixed.max_price_age_stream_seconds') : config('predict.fixed.max_price_age_seconds'));
    }

    /**
     * The figures players see from this outcome's book: the price, and whether its spread and depth pass the
     * betting guards (a failed guard withdraws the offered multiplier).
     *
     * @return array{midpoint: string|null, reliable: bool, spread_ok: bool, depth_ok: bool}
     */
    public function shownFigures(): array
    {
        return [
            'midpoint' => $this->midpoint === null ? null : bcadd((string) $this->midpoint, '0', 6),
            'reliable' => (bool) $this->price_reliable,
            'spread_ok' => $this->spread !== null && bccomp((string) $this->spread, (string) config('predict.fixed.max_spread'), 6) <= 0,
            'depth_ok' => $this->depth_usd !== null && bccomp((string) $this->depth_usd, (string) config('predict.fixed.min_depth_usd'), 2) >= 0,
        ];
    }

    /**
     * Why this outcome's price may not be bet on now, or null when it may: the stricter betting guards in
     * `predict.fixed` (age, spread, depth near the best ask).
     */
    public function bettingGuardFailure(): ?string
    {
        if (! $this->price_reliable || $this->midpoint === null || $this->price_captured_at === null) {
            return 'no_price';
        }

        if ($this->price_captured_at->lt(now()->subSeconds(self::maxBettingAgeSeconds()))) {
            return 'stale_price';
        }

        if ($this->spread === null || bccomp((string) $this->spread, (string) config('predict.fixed.max_spread'), 6) > 0) {
            return 'wide_spread';
        }

        if ($this->depth_usd === null || bccomp((string) $this->depth_usd, (string) config('predict.fixed.min_depth_usd'), 2) < 0) {
            return 'thin_book';
        }

        return null;
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
