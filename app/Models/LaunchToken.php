<?php

namespace App\Models;

use Database\Factories\LaunchTokenFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single-use ticket kadi gives a logged-in player to enter one game. The game redeems it once, within
 * its short life, for a player token. Only the sha256 of the token is kept.
 */
class LaunchToken extends Model
{
    /** @use HasFactory<LaunchTokenFactory> */
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'game_id',
        'token_hash',
        'expires_at',
        'redeemed_at',
        'redeemed_by_api_key_id',
        'ip',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'redeemed_at' => 'datetime',
        ];
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
