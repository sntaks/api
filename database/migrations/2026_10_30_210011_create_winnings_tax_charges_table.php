<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('winnings_tax_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wager_settlement_id')->constrained('wager_settlements');
            $table->string('kind', 20);
            $table->foreignId('wager_id')->constrained('wagers');
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('customer_id')->constrained('customers');
            $table->foreignId('wallet_id')->constrained('wallets');
            $table->foreignId('ledger_entry_id')->constrained('ledger_entries');
            $table->decimal('tax_base', 14, 2);
            $table->decimal('rate', 6, 4);
            $table->decimal('amount', 14, 2);
            $table->foreignId('remittance_id')->nullable()->constrained('winnings_tax_remittances')->nullOnDelete();
            $table->timestamp('charged_at');
            $table->timestamps();

            $table->unique(['wager_settlement_id', 'kind'], 'uniq_wht_settlement_kind');
            $table->index('charged_at', 'idx_wht_charged_at');
            $table->index(['remittance_id', 'charged_at'], 'idx_wht_remittance_charged_at');
            $table->index('customer_id', 'idx_wht_customer');
            $table->index('product_id', 'idx_wht_product');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('winnings_tax_charges');
    }
};
