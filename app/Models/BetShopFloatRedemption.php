<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Float paid back to a shop owner outside the API (M-Pesa B2C or bank), recorded by GMS. Never edited or
 * deleted: a wrong one is voided, which puts the amount back on the float.
 */
class BetShopFloatRedemption extends Model
{
    protected $fillable = [
        'bet_shop_id',
        'amount',
        'payment_reference',
        'note',
        'ledger_entry_id',
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

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class);
    }

    /**
     * @param  Builder<BetShopFloatRedemption>  $query
     * @return Builder<BetShopFloatRedemption>
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
