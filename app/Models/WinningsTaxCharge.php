<?php

namespace App\Models;

use Database\Factories\WinningsTaxChargeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Winnings tax withheld from one wager settlement (kind charge), or given back when that settlement was
 * reversed (kind reversal, a negative amount). Owed to KRA until a remittance takes it: a reversal is a
 * credit that lowers the return it falls in, even when its charge was remitted in an earlier one.
 */
class WinningsTaxCharge extends Model
{
    /** @use HasFactory<WinningsTaxChargeFactory> */
    use HasFactory;

    public const KIND_CHARGE = 'charge';

    public const KIND_REVERSAL = 'reversal';

    protected $fillable = [
        'wager_settlement_id',
        'kind',
        'wager_id',
        'product_id',
        'customer_id',
        'wallet_id',
        'ledger_entry_id',
        'tax_base',
        'rate',
        'amount',
        'remittance_id',
        'charged_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tax_base' => 'decimal:2',
            'rate' => 'decimal:4',
            'amount' => 'decimal:2',
            'charged_at' => 'datetime',
        ];
    }

    public function isRemitted(): bool
    {
        return $this->remittance_id !== null;
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(WagerSettlement::class, 'wager_settlement_id');
    }

    public function wager(): BelongsTo
    {
        return $this->belongsTo(Wager::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class);
    }

    public function remittance(): BelongsTo
    {
        return $this->belongsTo(WinningsTaxRemittance::class, 'remittance_id');
    }
}
