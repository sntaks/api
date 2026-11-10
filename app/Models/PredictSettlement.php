<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One settle or void run of a market and the frozen totals it paid from.
 */
class PredictSettlement extends Model
{
    public const KIND_SETTLE = 'settle';

    public const KIND_VOID = 'void';

    public const STATUS_RUNNING = 'running';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'predict_market_id',
        'run_no',
        'kind',
        'result',
        'status',
        'yes_minor',
        'no_minor',
        'final_multiplier_x100',
        'winners_count',
        'user_stake_minor',
        'payout_minor',
        'house_net_minor',
        'attempts',
        'error',
        'admin_user',
        'finished_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'yes_minor' => 'integer',
            'no_minor' => 'integer',
            'final_multiplier_x100' => 'integer',
            'winners_count' => 'integer',
            'user_stake_minor' => 'integer',
            'payout_minor' => 'integer',
            'house_net_minor' => 'integer',
            'attempts' => 'integer',
            'finished_at' => 'datetime',
        ];
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(PredictMarket::class, 'predict_market_id');
    }
}
