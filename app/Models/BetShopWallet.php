<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A bet shop's prepaid float: money the owner paid in and has not yet loaded to players. Kept apart from
 * `wallets` so shops never count in customer totals. Changed only through LedgerService.
 */
class BetShopWallet extends Model
{
    protected $fillable = [
        'bet_shop_id',
        'balance',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'balance' => 'decimal:2',
        ];
    }

    public function betShop(): BelongsTo
    {
        return $this->belongsTo(BetShop::class);
    }
}
