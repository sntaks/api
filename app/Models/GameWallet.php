<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GameWallet extends Model
{
    use HasFactory;

    protected $table = 'game_wallets';

    protected $fillable = [
        'game_id',
        'game_type',
        'balance',
        'status',
    ];

    public function gameTransactions()
    {
        return $this->hasMany(GameTransaction::class, 'game_wallet_id');
    }

    /**
     * Boot method to handle model events.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($gameWallet) {
            // Set default game_type as 1 (normal) if not explicitly set
            if (empty($gameWallet->game_type)) {
                $gameWallet->game_type = 1;
            }
        });
    }
}
