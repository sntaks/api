<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A player's Kadi Jet bet: game state only. The money is its wager (wager_reference on jet_classic); payout,
 * tax and net_payout are copied from the wager's settlement.
 */
class JetBet extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_WON = 'won';

    public const STATUS_LOST = 'lost';

    public const STATUS_VOID = 'void';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'stake' => 'decimal:2',
            'auto_cashout' => 'decimal:2',
            'cap_multiplier' => 'decimal:2',
            'cashout_multiplier' => 'decimal:2',
            'payout' => 'decimal:2',
            'tax' => 'decimal:2',
            'net_payout' => 'decimal:2',
            'placed_at' => 'datetime',
            'cashed_out_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }

    public function round(): BelongsTo
    {
        return $this->belongsTo(JetRound::class, 'jet_round_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function wager(): BelongsTo
    {
        return $this->belongsTo(Wager::class);
    }

    /**
     * The multiplier this bet cashes out at by itself: the player's auto target, never above the cap that keeps
     * its payout within the product's max_payout.
     */
    public function targetMultiplier(): string
    {
        if ($this->auto_cashout === null) {
            return (string) $this->cap_multiplier;
        }

        return bccomp((string) $this->auto_cashout, (string) $this->cap_multiplier, 2) < 0 ? (string) $this->auto_cashout : (string) $this->cap_multiplier;
    }
}
