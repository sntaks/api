<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Games are integrations (one launch URL, one lobby tile); products are the revenue lines finance
     * reports on. Kadi and its two competitions exist from day one; other games are added with
     * game:create and product:create when they are onboarded.
     */
    public function up(): void
    {
        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->string('launch_url', 255);
            $table->string('status', 20)->default('active');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->foreignId('game_id')->constrained();
            $table->string('name');
            $table->string('revenue_model', 20);
            $table->string('status', 20)->default('active');
            $table->decimal('max_stake', 12, 2)->nullable();
            $table->decimal('max_payout', 12, 2)->nullable();
            $table->decimal('winnings_tax_rate', 6, 4)->default(0);
            $table->timestamps();
        });

        $now = now();

        $kadiId = DB::table('games')->insertGetId([
            'code' => 'kadi',
            'name' => 'Kadi',
            'launch_url' => (string) config('services.kadi.play_url'),
            'status' => 'active',
            'sort_order' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('products')->insert(collect([
            'kadi' => 'Kadi',
            'kadi_tournament' => 'Kadi Tournament',
            'kadi_jackpot' => 'Kadi Jackpot',
        ])->map(fn (string $name, string $code) => [
            'code' => $code,
            'game_id' => $kadiId,
            'name' => $name,
            'revenue_model' => 'rake',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ])->values()->all());
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
        Schema::dropIfExists('games');
    }
};
