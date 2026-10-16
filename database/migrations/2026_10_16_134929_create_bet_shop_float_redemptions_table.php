<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Float paid back to a shop owner. GMS pays the owner outside the API (M-Pesa B2C or bank) and records it
     * here; the shop float goes down by the amount. Voiding puts it back.
     */
    public function up(): void
    {
        Schema::create('bet_shop_float_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bet_shop_id')->constrained('bet_shops');
            $table->decimal('amount', 12, 2);
            $table->string('payment_reference', 100);
            $table->string('note', 255);
            $table->foreignId('ledger_entry_id')->nullable()->constrained('ledger_entries');
            $table->string('recorded_by', 100)->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('voided_by', 100)->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->foreignId('void_ledger_entry_id')->nullable()->constrained('ledger_entries');
            $table->timestamps();

            $table->index(['bet_shop_id', 'created_at'], 'idx_shop_float_redemptions_shop_created');
            $table->index(['created_at', 'voided_at'], 'idx_shop_float_redemptions_created_voided');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bet_shop_float_redemptions');
    }
};
