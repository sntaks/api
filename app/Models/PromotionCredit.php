<?php

namespace App\Models;

use Database\Factories\PromotionCreditFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A house-funded credit to a customer's main wallet, such as the signup bonus. The house wallet pays the
 * gross amount; excise duty (when on) is then taken from the customer, who keeps the net amount.
 */
class PromotionCredit extends Model
{
    /** @use HasFactory<PromotionCreditFactory> */
    use HasFactory;

    public const PROMOTION_SIGNUP_BONUS = 'signup_bonus';

    public const STATUS_GRANTED = 'granted';

    protected $fillable = [
        'customer_id',
        'wallet_id',
        'promotion',
        'promo_code_id',
        'gross_amount',
        'rate',
        'excise_amount',
        'net_amount',
        'status',
        'ledger_entry_id',
        'house_ledger_entry_id',
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
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class);
    }

    public function houseLedgerEntry(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class, 'house_ledger_entry_id');
    }

    public function exciseDutyCharge(): HasOne
    {
        return $this->hasOne(ExciseDutyCharge::class);
    }
}
