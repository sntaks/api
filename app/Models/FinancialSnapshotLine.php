<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One account of a daily financial snapshot, for one product or (product_id null) the whole platform:
 * the per-product split of the balance sheet (section 5.7), kept apart from the fixed snapshot columns.
 */
class FinancialSnapshotLine extends Model
{
    protected $fillable = [
        'snapshot_date',
        'product_id',
        'account',
        'amount',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
