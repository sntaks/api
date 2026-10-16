<?php

namespace App\Models;

use Database\Factories\ComplaintFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A customer's complaint about the result of a game, tournament or jackpot. While it is pending, the
 * disputed winnings sit in dispute escrow (see DisputedTransaction).
 */
class Complaint extends Model
{
    /** @use HasFactory<ComplaintFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending_dispute';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_RESOLVED, self::STATUS_REJECTED, self::STATUS_CANCELLED];

    public const SUBJECT_GAME = 'game';

    public const SUBJECT_TOURNAMENT = 'tournament';

    public const SUBJECT_JACKPOT = 'jackpot';

    public const SUBJECTS = [self::SUBJECT_GAME, self::SUBJECT_TOURNAMENT, self::SUBJECT_JACKPOT];

    protected $fillable = [
        'complaint_id',
        'customer_id',
        'subject_type',
        'game_wallet_id',
        'competition_wallet_id',
        'reason',
        'description',
        'status',
        'disputed_amount',
        'held_amount',
        'shortfall_amount',
        'refunded_amount',
        'house_cuts_reversed',
        'released_amount',
        'filed_by',
        'resolution_note',
        'closed_by',
        'closed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'disputed_amount' => 'decimal:2',
            'held_amount' => 'decimal:2',
            'shortfall_amount' => 'decimal:2',
            'refunded_amount' => 'decimal:2',
            'house_cuts_reversed' => 'decimal:2',
            'released_amount' => 'decimal:2',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<Complaint>  $query
     * @return Builder<Complaint>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function gameWallet(): BelongsTo
    {
        return $this->belongsTo(GameWallet::class);
    }

    public function competitionWallet(): BelongsTo
    {
        return $this->belongsTo(CompetitionWallet::class);
    }

    public function disputedTransactions(): HasMany
    {
        return $this->hasMany(DisputedTransaction::class);
    }

    /**
     * The credits paid to players when the complaint was resolved.
     */
    public function refunds(): MorphMany
    {
        return $this->morphMany(LedgerEntry::class, 'referenceable')
            ->where('entry_type', 'dispute_refund')
            ->where('wallet_type', LedgerEntry::WALLET_TYPE_WALLET)
            ->orderBy('id');
    }
}
