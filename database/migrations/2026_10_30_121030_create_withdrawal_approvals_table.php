<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * M-Pesa withdrawals held for approval (D15): the money has left the wallet, the payout waits for GMS to
     * approve it (pay) or reject it (refund), or for the player to cancel it (refund). Decided once.
     */
    public function up(): void
    {
        Schema::create('withdrawal_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('withdraw_id')->unique()->constrained('outgoing_payments')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            // The verified number at request time: the payout goes here even if the player changes phone.
            $table->string('phone_no', 20);
            $table->string('status', 20);
            $table->string('requested_by', 100)->nullable();
            $table->string('decided_by', 100)->nullable();
            $table->string('admin_user', 100)->nullable();
            $table->string('reason', 500)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('withdrawal_approvals');
    }
};
