<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Float a shop owner bought by paying the paybill (bill ref SHOP-<shop_no>), credited to the shop's float
 * wallet from the unmatched deposit. Never edited or deleted: a wrong one is voided, which reverses the
 * credit and puts the deposit back to unmatched.
 */
class BetShopFloatPurchase extends Model
{
    protected $fillable = [
        'bet_shop_id',
        'deposit_id',
        'amount',
        'ledger_entry_id',
        'note',
        'recorded_by',
        'voided_at',
        'voided_by',
        'void_reason',
        'void_ledger_entry_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'voided_at' => 'datetime',
        ];
    }

    public function betShop(): BelongsTo
    {
        return $this->belongsTo(BetShop::class);
    }

    public function deposit(): BelongsTo
    {
        return $this->belongsTo(Deposit::class);
    }

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class);
    }

    /**
     * @param  Builder<BetShopFloatPurchase>  $query
     * @return Builder<BetShopFloatPurchase>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('voided_at');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }
}
