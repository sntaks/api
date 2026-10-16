<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Coin extends Model
{
    use HasFactory;

    protected $table = 'coins';

    protected $fillable = [
        'customer_id',
        'coins',
        'status',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }
}
