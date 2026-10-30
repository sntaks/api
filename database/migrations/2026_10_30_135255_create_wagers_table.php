<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wagers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products');
            // Nullable: a tombstone (a void for a reference that was never placed) has no player
            $table->foreignId('customer_id')->nullable()->constrained('customers');
            $table->foreignId('wallet_id')->nullable()->constrained('wallets');
            $table->foreignId('wager_round_id')->nullable()->constrained('wager_rounds');
            $table->foreignId('api_key_id')->constrained('api_keys');
            $table->string('reference', 100);
            $table->string('funding', 20);
            $table->decimal('stake', 12, 2)->default(0);
            $table->decimal('free_bet_amount', 12, 2)->default(0);
            $table->decimal('balance', 12, 2)->default(0);
            $table->string('status', 20)->default('open');
            $table->boolean('is_tombstone')->default(false);
            $table->unsignedInteger('settlement_version')->default(0);
            $table->decimal('payout', 12, 2)->default(0);
            $table->decimal('bonus', 12, 2)->default(0);
            $table->decimal('tax', 12, 2)->default(0);
            $table->string('channel', 20)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('placed_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'reference']);
            $table->index(['product_id', 'status']);
            $table->index(['customer_id', 'placed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wagers');
    }
};
