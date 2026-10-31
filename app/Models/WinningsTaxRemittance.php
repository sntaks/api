<?php

namespace App\Models;

use Database\Factories\WinningsTaxRemittanceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A payment of winnings tax to KRA for a period. Voided with a reason, never edited or deleted.
 */
class WinningsTaxRemittance extends Model
{
    /** @use HasFactory<WinningsTaxRemittanceFactory> */
    use HasFactory;

    protected $fillable = [
        'period_start',
        'period_end',
        'amount_due',
        'amount_paid',
        'kra_reference',
        'paid_at',
        'recorded_by',
        'voided_at',
        'voided_by',
        'void_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'amount_due' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'paid_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<WinningsTaxRemittance>  $query
     * @return Builder<WinningsTaxRemittance>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('voided_at');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    public function charges(): HasMany
    {
        return $this->hasMany(WinningsTaxCharge::class, 'remittance_id');
    }
}
