<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class PendingBalance extends Model
{
    use HasFactory;

    protected $fillable = [
        'pending_id',
        'wallet_id',
        'customer_id',
        'amount',
        'type',
        'referenceable_type',
        'referenceable_id',
        'status',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'expires_at' => 'datetime',
        ];
    }

    public function referenceable(): MorphTo
    {
        return $this->morphTo();
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }
}
