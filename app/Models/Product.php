<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A revenue line finance reports on. Every ledger entry of a game carries its product; platform
 * entries (deposits, withdrawals, excise, promos) carry none.
 */
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    public const REVENUE_RAKE = 'rake';

    public const REVENUE_HOUSE_BANKED = 'house_banked';

    public const REVENUE_MODELS = [self::REVENUE_RAKE, self::REVENUE_HOUSE_BANKED];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_RETIRED = 'retired';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_SUSPENDED, self::STATUS_RETIRED];

    public const CODE_KADI = 'kadi';

    public const CODE_KADI_TOURNAMENT = 'kadi_tournament';

    public const CODE_KADI_JACKPOT = 'kadi_jackpot';

    /**
     * Codes never change, so a found id is kept for the life of the process; any product write clears it.
     *
     * @var array<string, int>
     */
    private static array $ids = [];

    protected $fillable = [
        'code',
        'game_id',
        'name',
        'revenue_model',
        'status',
        'min_stake',
        'max_stake',
        'max_payout',
        'winnings_tax_rate',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'min_stake' => 'decimal:2',
            'max_stake' => 'decimal:2',
            'max_payout' => 'decimal:2',
            'winnings_tax_rate' => 'decimal:4',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => self::$ids = []);
        static::deleted(fn () => self::$ids = []);
    }

    public static function idFor(string $code): ?int
    {
        if (! isset(self::$ids[$code])) {
            $id = static::where('code', $code)->value('id');

            if ($id === null) {
                return null;
            }

            self::$ids[$code] = (int) $id;
        }

        return self::$ids[$code];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
