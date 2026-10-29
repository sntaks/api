<?php

namespace App\Models;

use Database\Factories\DepositFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Deposit extends Model
{
    /** @use HasFactory<DepositFactory> */
    use HasFactory;

    /** The account number matched no customer, so nothing was credited. */
    public const STATUS_UNMATCHED = 0;

    public const STATUS_COMPLETED = 2;

    /** An unmatched deposit that was sent back to the payer (see DepositResolution). */
    public const STATUS_REFUNDED = 4;

    protected $table = 'incoming_payments';

    protected $fillable = [
        'trans_id',
        'trans_type',
        'trans_time',
        'trans_amount',
        'short_code',
        'bill_ref_no',
        'msisdn',
        'name',
        'status',
        'matched_by',
        'unmatched_reason',
    ];

    // Define polymorphic relationship
    public function transactions(): MorphMany
    {
        return $this->morphMany(Transaction::class, 'payment');
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    public function exciseDutyCharge(): HasOne
    {
        return $this->hasOne(ExciseDutyCharge::class);
    }

    public function resolution(): HasOne
    {
        return $this->hasOne(DepositResolution::class);
    }
}
