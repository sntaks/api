<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A payout from a referral wallet to the customer's M-Pesa, sent from the referral shortcode.
 * The wallet is debited when the withdrawal is requested and credited back if the payout fails.
 *
 * pending     debited, B2C request not yet accepted by Safaricom
 * processing  Safaricom accepted the request, waiting for the result callback
 * completed   the result callback reported success
 * failed      the request or the payout failed; the debit was reversed
 */
class ReferralWithdrawal extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_PROCESSING, self::STATUS_COMPLETED, self::STATUS_FAILED];

    protected $fillable = [
        'customer_id',
        'referral_wallet_id',
        'amount',
        'phone_no',
        'status',
        'conversation_id',
        'originator_conversation_id',
        'mpesa_receipt',
        'result_code',
        'result_desc',
        'ledger_entry_id',
        'reversal_entry_id',
        'requested_by',
        'settled_by',
        'settlement_note',
        'completed_at',
        'failed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /**
     * Withdrawals whose money has left, or may still leave, the referral wallet.
     *
     * @param  Builder<ReferralWithdrawal>  $query
     * @return Builder<ReferralWithdrawal>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_PROCESSING]);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(ReferralWallet::class, 'referral_wallet_id');
    }

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class);
    }

    public function reversalEntry(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class, 'reversal_entry_id');
    }
}
