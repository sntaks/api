<?php

namespace App\Domain\Jet;

use Illuminate\Support\Facades\DB;

/**
 * The Jet contract fence. Every Jet money write, round transition and snapshot read takes this lock FIRST, then
 * jet_rounds, jet_bets and finally the wager services' own locks (wager round, wagers, wallets, house).
 */
class JetLock
{
    public static function run(callable $callback): mixed
    {
        return DB::transaction(function () use ($callback) {
            // The UPDATE also takes SQLite's writer lock, where FOR UPDATE is ignored
            DB::table('jet_contract_state')->where('id', 1)->increment('revision', 0);
            DB::table('jet_contract_state')->where('id', 1)->lockForUpdate()->firstOrFail();

            return $callback();
        }, 3);
    }
}
