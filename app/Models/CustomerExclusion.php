<?php

namespace App\Models;

use Database\Factories\CustomerExclusionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A break from play a player took. While it runs the player may log in and withdraw, but not deposit, stake
 * or launch a game. Rows are never changed or deleted.
 */
class CustomerExclusion extends Model
{
    /** @use HasFactory<CustomerExclusionFactory> */
    use HasFactory;

    public const TYPE_COOL_OFF = 'cool_off';

    public const TYPE_SELF_EXCLUSION = 'self_exclusion';

    protected $fillable = [
        'customer_id',
        'type',
        'duration',
        'starts_at',
        'ends_at',
        'actor',
        'phone_hash',
        'id_no',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
