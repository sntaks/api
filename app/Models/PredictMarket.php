<?php

namespace App\Models;

use Database\Factories\PredictMarketFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A binary prediction market. Its bets are wagers in the wager round `round_reference` of `product`.
 */
class PredictMarket extends Model
{
    /** @use HasFactory<PredictMarketFactory> */
    use HasFactory;

    public const SOURCE_CUSTOM = 'custom';

    public const SOURCE_POLYMARKET = 'polymarket';

    public const ENGINE_POOL = 'pool';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_OPEN = 'open';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_SETTLED = 'settled';

    public const STATUS_VOIDED = 'voided';

    public const STATUSES = [
        self::STATUS_DRAFT, self::STATUS_OPEN, self::STATUS_SUSPENDED, self::STATUS_CLOSED,
        self::STATUS_RESOLVED, self::STATUS_SETTLED, self::STATUS_VOIDED,
    ];

    /** Statuses players can see; drafts are admin-only */
    public const PUBLIC_STATUSES = [
        self::STATUS_OPEN, self::STATUS_SUSPENDED, self::STATUS_CLOSED,
        self::STATUS_RESOLVED, self::STATUS_SETTLED, self::STATUS_VOIDED,
    ];

    public const SIDE_YES = 'yes';

    public const SIDE_NO = 'no';

    public const SIDES = [self::SIDE_YES, self::SIDE_NO];

    protected $fillable = [
        'source',
        'external_market_id',
        'condition_id',
        'protocol_version',
        'question',
        'description',
        'resolution_criteria',
        'resolution_source',
        'category',
        'image_url',
        'countries',
        'status',
        'engine',
        'product_id',
        'round_reference',
        'close_at',
        'resolution_deadline_at',
        'result',
        'cut_bp',
        'seed_minor',
        'start_probability_bp',
        'min_stake_minor',
        'max_stake_minor',
        'max_payout_minor',
        'min_multiplier_x100',
        'created_by',
        'opened_at',
        'closed_at',
        'resolved_at',
        'resolved_by',
        'settled_at',
        'voided_at',
        'void_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'countries' => 'array',
            'close_at' => 'datetime',
            'resolution_deadline_at' => 'datetime',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'resolved_at' => 'datetime',
            'settled_at' => 'datetime',
            'voided_at' => 'datetime',
            'cut_bp' => 'integer',
            'seed_minor' => 'integer',
            'start_probability_bp' => 'integer',
            'min_stake_minor' => 'integer',
            'max_stake_minor' => 'integer',
            'max_payout_minor' => 'integer',
            'min_multiplier_x100' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function pool(): HasOne
    {
        return $this->hasOne(PredictPool::class);
    }

    public function bets(): HasMany
    {
        return $this->hasMany(PredictBet::class);
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(PredictMarketBlock::class);
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(PredictSettlement::class);
    }

    public function isVisibleIn(string $country): bool
    {
        return in_array($country, $this->countries ?? [], true);
    }
}
