<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * An admin action on prediction markets. Append-only: rows are never updated or deleted.
 */
class PredictAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'predict_market_id',
        'action',
        'admin_user',
        'reason',
        'before',
        'after',
        'api_key_id',
        'ip',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('The prediction audit log is append-only'));
        static::deleting(fn () => throw new LogicException('The prediction audit log is append-only'));
    }
}
