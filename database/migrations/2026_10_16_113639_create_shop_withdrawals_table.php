<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cash withdrawals at a bet shop. The player asks for one and gets a one-time pickup code; the amount
     * moves out of their wallet into the withdrawal (`balance`, a ledger wallet of its own) until the shop
     * pays it against the code (it then moves to the shop float), or it is cancelled, rejected or expires
     * (it goes back to the wallet).
     */
    public function up(): void
    {
        Schema::create('shop_withdrawals', function (Blueprint $table) {
            $table->id();
            $table->uuid('withdrawal_id')->unique();
            $table->foreignId('bet_shop_id')->constrained('bet_shops');
            $table->foreignId('customer_id')->constrained('customers');
            $table->foreignId('wallet_id')->constrained('wallets');
            $table->decimal('amount', 12, 2);
            $table->decimal('balance', 12, 2)->default(0);
            $table->string('status', 20)->default('pending');
            $table->string('pickup_code_hash');
            $table->unsignedTinyInteger('failed_attempts')->default(0);
            $table->timestamp('expires_at');
            $table->foreignId('transaction_id')->nullable()->constrained('transactions');
            $table->string('requested_by', 100)->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('closed_by', 100)->nullable();
            $table->string('close_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['bet_shop_id', 'status'], 'idx_shop_withdrawals_shop_status');
            $table->index(['customer_id', 'status'], 'idx_shop_withdrawals_customer_status');
            $table->index(['status', 'expires_at'], 'idx_shop_withdrawals_status_expires');
        });

        Schema::table('financial_snapshots', function (Blueprint $table) {
            $table->decimal('shop_withdrawals_held_total', 14, 2)->default(0)->after('bet_shop_floats_total');
        });
    }

    public function down(): void
    {
        Schema::table('financial_snapshots', function (Blueprint $table) {
            $table->dropColumn('shop_withdrawals_held_total');
        });

        Schema::dropIfExists('shop_withdrawals');
    }
};
