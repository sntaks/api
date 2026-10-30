<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An STK top-up prompt Safaricom accepted. It counts against the player's deposit limits for a few minutes,
 * until its money arrives through the paybill confirmation.
 */
class StkPush extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'customer_id',
        'amount',
        'checkout_request_id',
        'deposit_id',
        'settled_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'settled_at' => 'datetime',
        ];
    }
}
