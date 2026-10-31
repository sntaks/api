<?php

namespace App\Models;

use Database\Factories\FinancialSnapshotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FinancialSnapshot extends Model
{
    /** @use HasFactory<FinancialSnapshotFactory> */
    use HasFactory;

    protected $fillable = [
        'snapshot_date',
        'customer_wallets_total',
        'house_wallet_balance',
        'game_escrow_total',
        'competition_escrow_total',
        'stuck_escrow_total',
        'coin_liability',
        'pending_holds_total',
        'unmatched_deposits_total',
        'excise_duty_payable',
        'winnings_tax_payable',
        'open_wagers_total',
        'disputed_funds_total',
        'referral_wallets_total',
        'bet_shop_floats_total',
        'shop_withdrawals_held_total',
        'mpesa_balances',
        'taken_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
            'customer_wallets_total' => 'decimal:2',
            'house_wallet_balance' => 'decimal:2',
            'game_escrow_total' => 'decimal:2',
            'competition_escrow_total' => 'decimal:2',
            'stuck_escrow_total' => 'decimal:2',
            'coin_liability' => 'decimal:2',
            'pending_holds_total' => 'decimal:2',
            'unmatched_deposits_total' => 'decimal:2',
            'excise_duty_payable' => 'decimal:2',
            'winnings_tax_payable' => 'decimal:2',
            'open_wagers_total' => 'decimal:2',
            'disputed_funds_total' => 'decimal:2',
            'referral_wallets_total' => 'decimal:2',
            'bet_shop_floats_total' => 'decimal:2',
            'shop_withdrawals_held_total' => 'decimal:2',
            'mpesa_balances' => 'array',
            'taken_at' => 'datetime',
        ];
    }
}
