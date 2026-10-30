<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * STK top-up prompts Safaricom accepted. Their money arrives later through the paybill confirmation, so a
     * recent push counts against the player's deposit limits until then.
     */
    public function up(): void
    {
        Schema::create('stk_pushes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('checkout_request_id', 100)->nullable();
            // Set when the push's money arrives, so it stops counting as pending.
            $table->foreignId('deposit_id')->nullable()->constrained('incoming_payments')->nullOnDelete();
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['customer_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stk_pushes');
    }
};
