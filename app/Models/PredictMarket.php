<?php

namespace App\Models;

use Database\Factories\PredictMarketFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

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

    /** Imported markets: fixed odds priced from Polymarket's reference price */
    public const ENGINE_REFERENCE = 'reference';

    /** Custom markets priced by an LMSR market maker */
    public const ENGINE_LMSR = 'lmsr';

    /** Engines whose bets need a quote (a locked price) */
    public const QUOTED_ENGINES = [self::ENGINE_REFERENCE, self::ENGINE_LMSR];

    public const SUSPENDED_BY_ADMIN = 'admin';

    /** Suspended by the price sync (stale, unreliable or unreachable prices); lifted automatically */
    public const SUSPENDED_FOR_PRICE = 'price';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_OPEN = 'open';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_SETTLED = 'settled';

    public const STATUS_VOIDED = 'voided';

    /** A draft an admin (or the sync, when it closed upstream) turned down; the sync never re-imports it */
    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_DRAFT, self::STATUS_OPEN, self::STATUS_SUSPENDED, self::STATUS_CLOSED,
        self::STATUS_RESOLVED, self::STATUS_SETTLED, self::STATUS_VOIDED, self::STATUS_REJECTED,
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
        'predict_event_id',
        'external_market_id',
        'condition_id',
        'protocol_version',
        'external_slug',
        'external_question',
        'external_end_at',
        'external_game_start_at',
        'external_closed',
        'external_volume',
        'external_liquidity',
        'external_spread',
        'external_synced_at',
        'overridden_fields',
        'suspension_cause',
        'rejected_at',
        'reject_reason',
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
        'margin_bp',
        'seed_minor',
        'liquidity_minor',
        'start_probability_bp',
        'min_stake_minor',
        'max_stake_minor',
        'max_payout_minor',
        'max_liability_minor',
        'max_customer_stake_minor',
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
            'overridden_fields' => 'array',
            'external_end_at' => 'datetime',
            'external_game_start_at' => 'datetime',
            'margin_bp' => 'integer',
            'max_liability_minor' => 'integer',
            'max_customer_stake_minor' => 'integer',
            'external_closed' => 'boolean',
            'external_volume' => 'decimal:2',
            'external_liquidity' => 'decimal:2',
            'external_spread' => 'decimal:6',
            'external_synced_at' => 'datetime',
            'rejected_at' => 'datetime',
            'close_at' => 'datetime',
            'resolution_deadline_at' => 'datetime',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'resolved_at' => 'datetime',
            'settled_at' => 'datetime',
            'voided_at' => 'datetime',
            'cut_bp' => 'integer',
            'seed_minor' => 'integer',
            'liquidity_minor' => 'integer',
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

    public function event(): BelongsTo
    {
        return $this->belongsTo(PredictEvent::class, 'predict_event_id');
    }

    public function outcomes(): HasMany
    {
        return $this->hasMany(PredictOutcome::class);
    }

    public function isImported(): bool
    {
        return $this->source === self::SOURCE_POLYMARKET;
    }

    /**
     * When betting stops. A pool market takes bets until it closes. An imported market stops at the game's start
     * time when Polymarket gives one (never after the close time), otherwise `predict.fixed.cutoff_minutes` before
     * it closes: Polymarket's end date is often after kick-off.
     */
    public function bettingClosesAt(): Carbon
    {
        if (! $this->isImported()) {
            return $this->close_at;
        }

        if ($this->external_game_start_at !== null) {
            return $this->external_game_start_at->lt($this->close_at) ? $this->external_game_start_at : $this->close_at;
        }

        return $this->close_at->copy()->subMinutes((int) config('predict.fixed.cutoff_minutes'));
    }

    /**
     * Whether players can bet on it now: open, and before the betting cut-off.
     */
    public function takesBets(): bool
    {
        return $this->status === self::STATUS_OPEN && $this->bettingClosesAt()->isFuture();
    }

    public function lmsrState(): HasOne
    {
        return $this->hasOne(PredictLmsrState::class);
    }

    public function needsQuote(): bool
    {
        return in_array($this->engine, self::QUOTED_ENGINES, true);
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(PredictResolutionProposal::class);
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(PredictCorrection::class);
    }

    public function exposures(): HasMany
    {
        return $this->hasMany(PredictExposure::class);
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
