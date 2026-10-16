<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Cash a player handed over at a shop, loaded to their wallet from the shop's float. The shop float goes
 * down by the gross amount; the player keeps the net after excise duty. Never edited or deleted: a mistake
 * is reversed by GMS, never by the shop.
 */
class BetShopLoad extends Model
{
    public const STATUS_COMPLETED = 'completed';

    public const STATUS_REVERSED = 'reversed';

    protected $fillable = [
        'load_id',
        'bet_shop_id',
        'customer_id',
        'wallet_id',
        'gross_amount',
        'excise_amount',
        'net_amount',
        'status',
        'idempotency_key',
        'shop_ledger_entry_id',
        'customer_ledger_entry_id',
        'loaded_by',
        'reversed_at',
        'reversed_by',
        'reversal_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'gross_amount' => 'decimal:2',
            'excise_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'reversed_at' => 'datetime',
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

    public function exciseDutyCharge(): HasOne
    {
        return $this->hasOne(ExciseDutyCharge::class);
    }

    public function shopLedgerEntry(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class, 'shop_ledger_entry_id');
    }

    public function customerLedgerEntry(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class, 'customer_ledger_entry_id');
    }

    public function isReversed(): bool
    {
        return $this->status === self::STATUS_REVERSED;
    }
}
