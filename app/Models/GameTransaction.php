<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GameTransaction extends Model
{
    use HasFactory;

    protected $table = 'game_transactions';

    protected $fillable = [
        'game_wallet_id',
        'customer_id',
        'payment_type',
        'amount',
        'status',
    ];

    protected static function boot()
    {
        parent::boot();
    }

    public function gameWallet()
    {
        return $this->belongsTo(GameWallet::class, 'game_wallet_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }
}
