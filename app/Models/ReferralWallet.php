<?php

namespace App\Models;

use Database\Factories\ReferralWalletFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Referral bonuses a customer has earned, kept apart from their main wallet and ledgered with
 * wallet_type `referral`. It only accrues for now: nothing can be spent or withdrawn from it.
 */
class ReferralWallet extends Model
{
    /** @use HasFactory<ReferralWalletFactory> */
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'balance',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'balance' => 'decimal:2',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function bonuses(): HasMany
    {
        return $this->hasMany(ReferralBonus::class);
    }
}
