<?php

namespace App\Models;

use App\Util\Badge;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompetitionWallet extends Model
{
    use HasFactory;

    protected $table = 'competition_wallets';

    protected $fillable = [
        'competition_id', // Competition Unique Identifier => comes from game side.
        'cmp_uid', // Competition Unique Identifier => Tracks which wallet for which competition.
        'game_type', // 1 - Tournament, 2 - Jackpot
        'customer_id',
        'level',
        'jp_rounds', // Number of Rounds in Competition
        'balance',
        'status',
        'comments', // Logs
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function transactions()
    {
        return $this->hasMany(CompetitionTransaction::class, 'competition_wallet_id');
    }

    public function complaints(): HasMany
    {
        return $this->hasMany(Complaint::class);
    }

    /**
     * A wallet with a pending complaint is frozen: no payout to or from it and no withdrawal.
     */
    public function isUnderDispute(): bool
    {
        return $this->complaints()->pending()->exists();
    }

    /**
     * Get the competition type as a string based on the game type.
     *
     * @return string Returns "Tournament" if game_type is 1, "Jackpot" if game_type is 2,
     *                or "Unknown" if game_type does not match any predefined type.
     */
    public function getCompetitionType(int $game_type): string
    {
        return match ($game_type) {
            1 => 'Tournament',
            2 => 'Jackpot',
            default => 'Unknown',
        };
    }

    /**
     * Boot method to handle model events.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($competitionWallet) {
            // Set comments if not explicitly set
            if (empty($competitionWallet->comments)) {
                $competitionName = $competitionWallet->getCompetitionType($competitionWallet->game_type);
                $competitionWallet->comments = Badge::set('info', $competitionName).' Wallet Initialized';
            }
        });
    }
}
