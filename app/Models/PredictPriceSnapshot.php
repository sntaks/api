<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One point of an imported market's YES reference price history.
 */
class PredictPriceSnapshot extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'predict_outcome_id',
        'midpoint',
        'captured_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'midpoint' => 'decimal:6',
            'captured_at' => 'datetime',
        ];
    }

    public function outcome(): BelongsTo
    {
        return $this->belongsTo(PredictOutcome::class, 'predict_outcome_id');
    }
}
