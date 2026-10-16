<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A player's cash withdrawal at a bet shop. While pending it holds the amount (`balance`) out of the
 * player's wallet; it closes as paid (the shop handed over the cash against the pickup code), cancelled
 * (by the player, or after too many wrong codes), rejected (by the shop) or expired.
 */
class ShopWithdrawal extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_EXPIRED = 'expired';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_PAID, self::STATUS_CANCELLED, self::STATUS_REJECTED, self::STATUS_EXPIRED];

    protected $fillable = [
        'withdrawal_id',
        'bet_shop_id',
        'customer_id',
        'wallet_id',
        'amount',
        'balance',
        'status',
        'pickup_code_hash',
        'failed_attempts',
        'expires_at',
        'transaction_id',
        'requested_by',
        'closed_at',
        'closed_by',
        'close_reason',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'pickup_code_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'balance' => 'decimal:2',
            'failed_attempts' => 'integer',
            'expires_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function betShop(): BelongsTo
    {
        return $this->belongsTo(BetShop::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function hasExpired(): bool
    {
        return $this->isPending() && $this->expires_at->isPast();
    }
}
