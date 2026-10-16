<?php

namespace App\Models;

use Database\Factories\BetShopFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A physical bet shop. Not a customer and cannot play: it holds a prepaid float the owner bought through
 * the paybill (bill ref SHOP-<shop_no>) and spends loading players' wallets with cash.
 */
class BetShop extends Model
{
    /** @use HasFactory<BetShopFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    /** Shop keys are refused; the float is kept and can still be bought. */
    public const STATUS_SUSPENDED = 'suspended';

    /** For good, only once the float is empty. */
    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_SUSPENDED, self::STATUS_CLOSED];

    protected $fillable = [
        'shop_no',
        'name',
        'owner_name',
        'owner_phone',
        'location',
        'status',
        'status_reason',
        'notes',
        'created_by',
    ];

    public function wallet(): HasOne
    {
        return $this->hasOne(BetShopWallet::class);
    }

    public function loads(): HasMany
    {
        return $this->hasMany(BetShopLoad::class);
    }

    public function floatPurchases(): HasMany
    {
        return $this->hasMany(BetShopFloatPurchase::class);
    }

    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
