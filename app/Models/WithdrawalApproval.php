<?php

namespace App\Models;

use Database\Factories\WithdrawalApprovalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An M-Pesa withdrawal of approval_threshold or more, held for approval. The money is already out of the
 * wallet (the withdrawal ledger entry); GMS approves it (B2C pays the stored phone) or rejects it (refund),
 * or the player cancels it (refund). It is decided once.
 */
class WithdrawalApproval extends Model
{
    /** @use HasFactory<WithdrawalApprovalFactory> */
    use HasFactory;

    public const STATUS_AWAITING = 'awaiting';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [self::STATUS_AWAITING, self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CANCELLED];

    protected $fillable = [
        'withdraw_id',
        'customer_id',
        'amount',
        'phone_no',
        'status',
        'requested_by',
        'decided_by',
        'admin_user',
        'reason',
        'decided_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'decided_at' => 'datetime',
        ];
    }

    public function withdraw(): BelongsTo
    {
        return $this->belongsTo(Withdraw::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }
}
