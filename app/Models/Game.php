<?php

namespace App\Models;

use Database\Factories\GameFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One integration on the platform: a launch URL, its game keys and one lobby tile. Kadi is seeded;
 * other games are created with game:create.
 */
class Game extends Model
{
    /** @use HasFactory<GameFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_RETIRED = 'retired';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_SUSPENDED, self::STATUS_RETIRED];

    public const CODE_KADI = 'kadi';

    /**
     * Codes never change, so a found id is kept for the life of the process; any game write clears it.
     *
     * @var array<string, int>
     */
    private static array $ids = [];

    protected $fillable = [
        'code',
        'name',
        'launch_url',
        'status',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
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

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
