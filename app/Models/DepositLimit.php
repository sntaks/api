<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A player's own limit on what they may deposit within a rolling period (day, week or month). A raise or a
 * removal waits as the pending value until pending_from.
 */
class DepositLimit extends Model
{
    protected $fillable = [
        'customer_id',
        'period',
        'amount',
        'pending_amount',
        'has_pending_change',
        'pending_from',
        'actor',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'pending_amount' => 'decimal:2',
            'has_pending_change' => 'boolean',
            'pending_from' => 'datetime',
        ];
    }

    /**
     * The limit in force now, in shillings, or null when there is none: the pending change once its 24 hours
     * have passed, the current amount before that.
     */
    public function effectiveAmount(): ?float
    {
        $amount = $this->has_pending_change && $this->pending_from?->lte(now()) ? $this->pending_amount : $this->amount;

        return $amount === null ? null : (float) $amount;
    }

    public function hours(): int
    {
        return (int) config("responsible_gambling.deposit_limit_periods.{$this->period}");
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
