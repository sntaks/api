<?php

use App\Util\PlayerNumber;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The 8-digit player number shop owners type to find a player, given to every existing customer.
     * New customers get one when they are created (Customer::creating).
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->char('player_no', 8)->nullable()->unique()->after('account_no');
        });

        DB::table('customers')->whereNull('player_no')->orderBy('id')->chunkById(500, function ($customers) {
            foreach ($customers as $customer) {
                DB::table('customers')->where('id', $customer->id)->update(['player_no' => PlayerNumber::generateUnique()]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['player_no']);
            $table->dropColumn('player_no');
        });
    }
};
