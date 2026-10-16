<?php

namespace App\Models;

use Database\Factories\ReferralFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One customer (referred) who signed up with another customer's (referrer) code.
 */
class Referral extends Model
{
    /** @use HasFactory<ReferralFactory> */
    use HasFactory;

    protected $fillable = [
        'referrer_id',
        'referred_id',
        'code_used',
        'verified_at',
        'first_deposit_id',
        'first_deposited_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'first_deposited_at' => 'datetime',
        ];
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'referrer_id')->withTrashed();
    }

    public function referred(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'referred_id')->withTrashed();
    }

    public function firstDeposit(): BelongsTo
    {
        return $this->belongsTo(Deposit::class, 'first_deposit_id');
    }

    public function bonuses(): HasMany
    {
        return $this->hasMany(ReferralBonus::class);
    }
}
