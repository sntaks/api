<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

class Stock extends Model
{
    use HasFactory, Notifiable;

    protected $table = 'stocks';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'amount',
        'status',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function getRemainingShares(): int
    {
        $totalActiveShares = Stock::where('status', 1)->sum('amount');

        return $totalActiveShares < 100 ? 100 - $totalActiveShares : 0;
    }

    public static function getActiveShares(): int
    {
        return Stock::where('status', 1)->sum('amount');
    }
}
