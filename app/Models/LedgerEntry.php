<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class LedgerEntry extends Model
{
    use HasFactory;

    public const WALLET_TYPE_WALLET = 'wallet';

    public const WALLET_TYPE_GAME = 'game_wallet';

    public const WALLET_TYPE_COMPETITION = 'competition_wallet';

    public const WALLET_TYPE_COIN = 'coin_wallet';

    public const WALLET_TYPE_DISPUTE = 'dispute';

    public const WALLET_TYPE_REFERRAL = 'referral';

    public const WALLET_TYPE_BET_SHOP = 'bet_shop';

    public const WALLET_TYPE_SHOP_WITHDRAWAL = 'shop_withdrawal';

    protected $fillable = [
        'entry_id',
        'entry_type',
        'referenceable_type',
        'referenceable_id',
        'wallet_type',
        'wallet_id',
        'customer_id',
        'product_id',
        'debit',
        'credit',
        'balance_before',
        'balance_after',
        'status',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
            'balance_before' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    /**
     * Entries that count towards a wallet balance. A reversed entry stays in the sum
     * because its offsetting `*_reversal` entry is counted too.
     *
     * @param  Builder<LedgerEntry>  $query
     * @return Builder<LedgerEntry>
     */
    public function scopeCountable(Builder $query): Builder
    {
        return $query->whereIn('status', ['settled', 'reversed']);
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

    /**
     * The game product this entry belongs to; null for platform entries.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
