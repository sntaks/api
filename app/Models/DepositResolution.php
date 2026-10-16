<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How an unmatched deposit was resolved: `assigned` to a customer's wallet, or `refunded` to the payer
 * outside the API. One per deposit; the M-Pesa record itself is never changed apart from its status.
 */
class DepositResolution extends Model
{
    public const ACTION_ASSIGNED = 'assigned';

    public const ACTION_REFUNDED = 'refunded';

    /** Owner money credited to the house wallet (HouseFunding). Removed if the funding is voided. */
    public const ACTION_HOUSE_FUNDED = 'house_funded';

    /** A shop owner's float purchase (BetShopFloatPurchase). Removed if the purchase is voided. */
    public const ACTION_SHOP_FLOAT = 'shop_float';

    protected $fillable = [
        'deposit_id',
        'action',
        'customer_id',
        'ledger_entry_id',
        'account_no_used',
        'mpesa_reference',
        'note',
        'resolved_by',
    ];

    public function deposit(): BelongsTo
    {
        return $this->belongsTo(Deposit::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class);
    }
}
