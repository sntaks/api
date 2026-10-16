<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

class ApiKey extends Model
{
    use HasFactory;

    /** Every route except the shop system's (the scope every key had before shop keys existed). */
    public const SCOPE_FULL = 'full';

    /** Only /v1/shop/* routes, always for its one bet shop. */
    public const SCOPE_SHOP = 'shop';

    protected $table = 'api_keys';

    protected $fillable = [
        'key',
        'name',
        'scope',
        'bet_shop_id',
        'rate_limit_multiplier',
        'is_active',
        'revoked_at',
        'revoked_by',
    ];

    /**
     * The key itself is never sent back after it is issued.
     *
     * @var list<string>
     */
    protected $hidden = [
        'key',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'rate_limit_multiplier' => 'integer',
            'revoked_at' => 'datetime',
        ];
    }

    public function betShop(): BelongsTo
    {
        return $this->belongsTo(BetShop::class);
    }

    /**
     * What the rate limiter needs about an active key (its id and multiplier), or null for a missing or
     * unknown key. Known keys are cached for five minutes (apikey:rate-limit clears it); unknown ones are
     * not cached, so made-up keys cannot fill the cache.
     *
     * @return array{id: int, multiplier: int}|null
     */
    public static function rateLimitProfileFor(?string $plainKey): ?array
    {
        if (blank($plainKey)) {
            return null;
        }

        $cacheKey = self::multiplierCacheKey($plainKey);

        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        $key = self::where('key', $plainKey)->where('is_active', true)->first(['id', 'rate_limit_multiplier']);

        if (! $key) {
            return null;
        }

        $profile = ['id' => (int) $key->id, 'multiplier' => max(1, (int) $key->rate_limit_multiplier)];
        Cache::put($cacheKey, $profile, now()->addMinutes(5));

        return $profile;
    }

    public static function multiplierCacheKey(string $plainKey): string
    {
        return 'api-key-rate-limit:'.hash('sha256', $plainKey);
    }

    public function isShopKey(): bool
    {
        return $this->scope === self::SCOPE_SHOP;
    }
}
