<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
            'revoked_at' => 'datetime',
        ];
    }

    public function betShop(): BelongsTo
    {
        return $this->belongsTo(BetShop::class);
    }

    public function isShopKey(): bool
    {
        return $this->scope === self::SCOPE_SHOP;
    }
}
