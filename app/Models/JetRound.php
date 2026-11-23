<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One Kadi Jet round. Everything about it, the crash point included, is fixed when it is created; the server
 * seed stays hidden until it is settled. Its wager round is the jet_classic round with reference = round_number.
 */
class JetRound extends Model
{
    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_BETTING = 'betting';

    public const STATUS_RUNNING = 'running';

    public const STATUS_CRASHED = 'crashed';

    public const STATUS_SETTLED = 'settled';

    /** Taken out of play; the wagers are still being voided. */
    public const STATUS_VOIDING = 'voiding';

    public const STATUS_VOIDED = 'voided';

    public const FINISHED = [self::STATUS_SETTLED, self::STATUS_VOIDED];

    protected $guarded = ['id'];

    protected $hidden = ['server_seed'];

    protected function casts(): array
    {
        return [
            'round_number' => 'integer',
            'revision' => 'integer',
            'nonce' => 'integer',
            'house_edge' => 'decimal:8',
            'max_multiplier_cap' => 'decimal:2',
            'acceleration_k' => 'decimal:8',
            'crash_multiplier' => 'decimal:2',
            'betting_closes_at' => 'datetime',
            'started_at' => 'datetime',
            'crashed_at' => 'datetime',
            'settled_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function bets(): HasMany
    {
        return $this->hasMany(JetBet::class);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, self::FINISHED, true);
    }

    public function crashRevealed(): bool
    {
        return in_array($this->status, [self::STATUS_CRASHED, self::STATUS_SETTLED], true);
    }
}
