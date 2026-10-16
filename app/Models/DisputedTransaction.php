<?php

namespace App\Models;

use Database\Factories\DisputedTransactionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One game or competition transaction under a complaint. The row doubles as the dispute escrow wallet
 * for that transaction: `balance` is the money currently held, ledgered with wallet_type `dispute`.
 */
class DisputedTransaction extends Model
{
    /** @use HasFactory<DisputedTransactionFactory> */
    use HasFactory;

    public const STATUS_HELD = 'held';

    public const STATUS_RELEASED = 'released';

    public const STATUS_REVERSED = 'reversed';

    protected $fillable = [
        'complaint_id',
        'disputable_type',
        'disputable_id',
        'customer_id',
        'source_wallet_type',
        'source_wallet_id',
        'amount',
        'held_amount',
        'shortfall_amount',
        'balance',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'held_amount' => 'decimal:2',
            'shortfall_amount' => 'decimal:2',
            'balance' => 'decimal:2',
        ];
    }

    /**
     * @param  Builder<DisputedTransaction>  $query
     * @return Builder<DisputedTransaction>
     */
    public function scopeHeld(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_HELD);
    }

    public function complaint(): BelongsTo
    {
        return $this->belongsTo(Complaint::class);
    }

    public function disputable(): MorphTo
    {
        return $this->morphTo();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }
}
