<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Purchase extends Model
{
    protected $table = 'purchases';

    protected $fillable = [
        'customer_id',
        'deposit_id',
        'purchase_type',
        'amount',
        'value',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function deposit(): BelongsTo
    {
        return $this->belongsTo(Deposit::class);
    }
}
