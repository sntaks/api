<?php

namespace App\Models;

use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class WalletTransaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory;

    protected $table = 'wallet_transactions';

    protected $fillable = [
        'transaction_id',
        'transaction_type',
        'sender_id',
        'receiver_id',
        'initiator_id',
        'amount',
        'status',
    ];

    protected static function booted()
    {
        static::creating(function ($transaction) {
            // Generate a unique ID if not already set
            $transaction->transaction_id = $transaction->transaction_id ?? strtoupper('TXN'.now()->format('YmdHis').'-'.Str::random(6));
        });
    }

    public function sender()
    {
        return $this->belongsTo(Wallet::class, 'sender_id');
    }

    public function receiver()
    {
        return $this->belongsTo(Wallet::class, 'receiver_id');
    }

    public function initiator()
    {
        return $this->belongsTo(Customer::class, 'initiator_id')->withTrashed();
    }
}
