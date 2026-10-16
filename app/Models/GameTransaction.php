<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameTransaction extends Model
{
    use HasFactory;

    protected $table = 'game_transactions';

    protected $fillable = [
        'game_wallet_id',
        'customer_id',
        'bet_shop_id',
        'payment_type',
        'amount',
        'status',
    ];

    /**
     * The bet shop the stake was made at, when the game server said so.
     */
    public function betShop(): BelongsTo
    {
        return $this->belongsTo(BetShop::class);
    }

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
