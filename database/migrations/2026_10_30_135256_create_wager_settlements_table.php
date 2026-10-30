<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wager_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wager_id')->constrained('wagers');
            $table->unsignedInteger('version');
            $table->string('outcome', 20);
            $table->decimal('payout', 12, 2)->default(0);
            $table->decimal('bonus', 12, 2)->default(0);
            $table->decimal('tax', 12, 2)->default(0);
            $table->decimal('tax_base', 12, 2)->default(0);
            $table->decimal('tax_rate', 6, 4)->default(0);
            $table->string('reason', 255)->nullable();
            $table->string('settled_by', 20)->default('game');
            $table->string('admin_user', 100)->nullable();
            $table->string('status', 20)->default('applied');
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversal_reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['wager_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wager_settlements');
    }
};
