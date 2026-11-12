<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An event imported from Polymarket; it groups one or more binary markets.
 */
class PredictEvent extends Model
{
    protected $fillable = [
        'source',
        'external_event_id',
        'slug',
        'title',
        'description',
        'image_url',
        'category',
        'tags',
        'starts_at',
        'ends_at',
        'is_closed',
        'last_synced_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_closed' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    public function markets(): HasMany
    {
        return $this->hasMany(PredictMarket::class);
    }
}
