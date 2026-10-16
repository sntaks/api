<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompetitionTransaction extends Model
{
    use HasFactory;

    protected $table = 'competition_transactions';

    protected $fillable = [
        'competition_wallet_id',
        'customer_id',
        'bet_shop_id',
        'payment_type',
        'amount',
        'status',
    ];

    /**
     * The bet shop the entry was made at, when the game server said so.
     */
    public function betShop(): BelongsTo
    {
        return $this->belongsTo(BetShop::class);
    }

    public function wallet()
    {
        return $this->belongsTo(CompetitionWallet::class, 'competition_wallet_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }
}
