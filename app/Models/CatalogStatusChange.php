<?php

namespace App\Models;

use Database\Factories\CatalogStatusChangeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One status change of a game or a product made in GMS: who made it and why. Rows are never updated.
 */
class CatalogStatusChange extends Model
{
    /** @use HasFactory<CatalogStatusChangeFactory> */
    use HasFactory;

    public const SUBJECT_GAME = 'game';

    public const SUBJECT_PRODUCT = 'product';

    public const UPDATED_AT = null;

    protected $fillable = [
        'subject_type',
        'subject_id',
        'from_status',
        'to_status',
        'reason',
        'actor',
        'admin_user',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subject_id' => 'integer',
        ];
    }
}
