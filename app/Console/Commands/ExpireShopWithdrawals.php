<?php

namespace App\Console\Commands;

use App\Services\ShopWithdrawalService;
use Illuminate\Console\Command;

class ExpireShopWithdrawals extends Command
{
    /**
     * @var string
     */
    protected $signature = 'shop-withdrawals:expire';

    /**
     * @var string
     */
    protected $description = 'Expire shop cash withdrawals nobody collected in time and give the money back to the players';

    public function handle(ShopWithdrawalService $withdrawals): int
    {
        $expired = $withdrawals->expireDue();

        $this->info("Expired {$expired} shop withdrawal(s).");

        return self::SUCCESS;
    }
}
