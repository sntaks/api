<?php

namespace App\Models;

use Database\Factories\WagerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A bet on a house-banked product. While a cash wager is open it holds the stake (`balance`) as its own
 * escrow, like a kadi game wallet. A free bet holds nothing: the voucher stays with the game.
 */
class Wager extends Model
{
    /** @use HasFactory<WagerFactory> */
    use HasFactory;

    public const FUNDING_CASH = 'cash';

    public const FUNDING_FREE_BET = 'free_bet';

    public const FUNDINGS = [self::FUNDING_CASH, self::FUNDING_FREE_BET];

    public const STATUS_OPEN = 'open';

    public const STATUS_WON = 'won';

    public const STATUS_LOST = 'lost';

    public const STATUS_VOID = 'void';

    public const STATUSES = [self::STATUS_OPEN, self::STATUS_WON, self::STATUS_LOST, self::STATUS_VOID];

    protected $fillable = [
        'product_id',
        'customer_id',
        'wallet_id',
        'wager_round_id',
        'api_key_id',
        'reference',
        'funding',
        'stake',
        'free_bet_amount',
        'balance',
        'status',
        'is_tombstone',
        'settlement_version',
        'payout',
        'bonus',
        'tax',
        'channel',
        'metadata',
        'placed_at',
        'settled_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stake' => 'decimal:2',
            'free_bet_amount' => 'decimal:2',
            'balance' => 'decimal:2',
            'payout' => 'decimal:2',
            'bonus' => 'decimal:2',
            'tax' => 'decimal:2',
            'is_tombstone' => 'boolean',
            'settlement_version' => 'integer',
            'metadata' => 'array',
            'placed_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function round(): BelongsTo
    {
        return $this->belongsTo(WagerRound::class, 'wager_round_id');
    }

    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class);
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(WagerSettlement::class);
    }
}
