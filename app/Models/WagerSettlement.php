<?php

namespace App\Models;

use Database\Factories\WagerSettlementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One version of a wager's outcome. The ledger entries of a settlement, and of its reversal, reference this row.
 */
class WagerSettlement extends Model
{
    /** @use HasFactory<WagerSettlementFactory> */
    use HasFactory;

    public const OUTCOME_WON = 'won';

    public const OUTCOME_LOST = 'lost';

    public const OUTCOME_VOID = 'void';

    public const OUTCOMES = [self::OUTCOME_WON, self::OUTCOME_LOST, self::OUTCOME_VOID];

    public const SETTLED_BY_GAME = 'game';

    public const SETTLED_BY_ADMIN = 'admin';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_REVERSED = 'reversed';

    protected $fillable = [
        'wager_id',
        'version',
        'outcome',
        'payout',
        'bonus',
        'tax',
        'tax_base',
        'tax_rate',
        'reason',
        'settled_by',
        'admin_user',
        'status',
        'reversed_at',
        'reversal_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'payout' => 'decimal:2',
            'bonus' => 'decimal:2',
            'tax' => 'decimal:2',
            'tax_base' => 'decimal:2',
            'tax_rate' => 'decimal:4',
            'reversed_at' => 'datetime',
        ];
    }

    public function wager(): BelongsTo
    {
        return $this->belongsTo(Wager::class);
    }
}
