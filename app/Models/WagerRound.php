<?php

namespace App\Models;

use Database\Factories\WagerRoundFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A group of wagers on one product that settle or void together (a crash round).
 */
class WagerRound extends Model
{
    /** @use HasFactory<WagerRoundFactory> */
    use HasFactory;

    public const STATUS_OPEN = 'open';

    public const STATUS_SETTLED = 'settled';

    public const STATUS_VOIDED = 'voided';

    public const STATUSES = [self::STATUS_OPEN, self::STATUS_SETTLED, self::STATUS_VOIDED];

    protected $fillable = [
        'product_id',
        'reference',
        'status',
        'closed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'closed_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function wagers(): HasMany
    {
        return $this->hasMany(Wager::class);
    }
}
