<?php

namespace App\Models;

use Database\Factories\ExciseDutyChargeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Excise duty taken from one M-Pesa wallet deposit, or from one promotion credit (deposit_id is then null).
 * Held as a liability until it is paid to KRA.
 */
class ExciseDutyCharge extends Model
{
    /** @use HasFactory<ExciseDutyChargeFactory> */
    use HasFactory;

    public const STATUS_CHARGED = 'charged';

    public const STATUS_REVERSED = 'reversed';

    protected $fillable = [
        'deposit_id',
        'promotion_credit_id',
        'bet_shop_load_id',
        'customer_id',
        'wallet_id',
        'ledger_entry_id',
        'gross_amount',
        'rate',
        'excise_amount',
        'net_amount',
        'status',
        'remittance_id',
        'charged_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'gross_amount' => 'decimal:2',
            'rate' => 'decimal:4',
            'excise_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'charged_at' => 'datetime',
        ];
    }

    /**
     * Charges that count towards excise owed: everything not reversed.
     *
     * @param  Builder<ExciseDutyCharge>  $query
     * @return Builder<ExciseDutyCharge>
     */
    public function scopeCharged(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_CHARGED);
    }

    public function isRemitted(): bool
    {
        return $this->remittance_id !== null;
    }

    public function deposit(): BelongsTo
    {
        return $this->belongsTo(Deposit::class);
    }

    public function promotionCredit(): BelongsTo
    {
        return $this->belongsTo(PromotionCredit::class);
    }

    public function betShopLoad(): BelongsTo
    {
        return $this->belongsTo(BetShopLoad::class);
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
        return $this->belongsTo(ExciseDutyRemittance::class, 'remittance_id');
    }
}
