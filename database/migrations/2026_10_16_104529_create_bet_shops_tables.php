<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Physical bet shops: each has one prepaid float wallet (kept apart from customer wallets), bought by
     * paying the paybill with bill ref SHOP-<shop_no>, and spent loading players' wallets with cash.
     */
    public function up(): void
    {
        Schema::create('bet_shops', function (Blueprint $table) {
            $table->id();
            $table->string('shop_no', 10)->unique();
            $table->string('name');
            $table->string('owner_name');
            $table->string('owner_phone', 20);
            $table->string('location')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->string('status_reason', 255)->nullable();
            $table->text('notes')->nullable();
            $table->string('created_by', 100)->nullable();
            $table->timestamps();
        });

        Schema::create('bet_shop_wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bet_shop_id')->unique()->constrained('bet_shops');
            $table->decimal('balance', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('bet_shop_float_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bet_shop_id')->constrained('bet_shops');
            $table->foreignId('deposit_id')->constrained('incoming_payments');
            $table->decimal('amount', 12, 2);
            $table->foreignId('ledger_entry_id')->constrained('ledger_entries');
            $table->string('note', 255);
            $table->string('recorded_by', 100)->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('voided_by', 100)->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->foreignId('void_ledger_entry_id')->nullable()->constrained('ledger_entries');
            $table->timestamps();

            $table->index(['deposit_id', 'voided_at'], 'idx_shop_float_purchases_deposit_voided');
            $table->index(['bet_shop_id', 'created_at'], 'idx_shop_float_purchases_shop_created');
        });

        Schema::create('bet_shop_loads', function (Blueprint $table) {
            $table->id();
            $table->uuid('load_id')->unique();
            $table->foreignId('bet_shop_id')->constrained('bet_shops');
            $table->foreignId('customer_id')->constrained('customers');
            $table->foreignId('wallet_id')->constrained('wallets');
            $table->decimal('gross_amount', 12, 2);
            $table->decimal('excise_amount', 12, 2)->default(0);
            $table->decimal('net_amount', 12, 2);
            $table->string('status', 20)->default('completed');
            $table->string('idempotency_key', 64);
            $table->foreignId('shop_ledger_entry_id')->nullable()->constrained('ledger_entries');
            $table->foreignId('customer_ledger_entry_id')->nullable()->constrained('ledger_entries');
            $table->string('loaded_by', 100)->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversed_by', 100)->nullable();
            $table->string('reversal_reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['bet_shop_id', 'idempotency_key'], 'uniq_shop_loads_shop_idempotency');
            $table->index(['customer_id', 'status', 'created_at'], 'idx_shop_loads_customer_status_created');
            $table->index(['bet_shop_id', 'created_at'], 'idx_shop_loads_shop_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bet_shop_loads');
        Schema::dropIfExists('bet_shop_float_purchases');
        Schema::dropIfExists('bet_shop_wallets');
        Schema::dropIfExists('bet_shops');
    }
};
