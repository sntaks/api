<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A bonus paid to a referrer when their referral reached a milestone. Each milestone pays once per referral.
 */
class ReferralBonus extends Model
{
    public const MILESTONE_SIGNUP = 'signup';

    public const MILESTONE_FIRST_DEPOSIT = 'first_deposit';

    public const MILESTONES = [self::MILESTONE_SIGNUP, self::MILESTONE_FIRST_DEPOSIT];

    protected $fillable = [
        'referral_id',
        'customer_id',
        'referred_id',
        'referral_wallet_id',
        'milestone',
        'amount',
        'ledger_entry_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function referral(): BelongsTo
    {
        return $this->belongsTo(Referral::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function referred(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'referred_id')->withTrashed();
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(ReferralWallet::class, 'referral_wallet_id');
    }

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class);
    }
}
