<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Game keys (scope `game`) are bound to one game. Every other key leaves it null.
     */
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->foreignId('game_id')->nullable()->after('bet_shop_id')->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropConstrainedForeignId('game_id');
        });
    }
};
