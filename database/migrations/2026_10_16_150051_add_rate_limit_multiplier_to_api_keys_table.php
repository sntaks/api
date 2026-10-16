<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A server-side key that many people work through (the GMS) gets a bigger rate-limit budget: every
     * per-minute limit is multiplied by this. 1 keeps the standard limits.
     */
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->unsignedSmallInteger('rate_limit_multiplier')->default(1)->after('bet_shop_id');
        });
    }

    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropColumn('rate_limit_multiplier');
        });
    }
};
