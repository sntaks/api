<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A player's own deposit limits, one per rolling period. A raise or a removal waits as the pending value
     * until pending_from; a cut applies at once. Whole shillings; null means no limit.
     */
    public function up(): void
    {
        Schema::create('deposit_limits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('period', 10);
            $table->decimal('amount', 12, 2)->nullable();
            $table->decimal('pending_amount', 12, 2)->nullable();
            $table->boolean('has_pending_change')->default(false);
            $table->timestamp('pending_from')->nullable();
            $table->string('actor', 100)->nullable();
            $table->timestamps();

            $table->unique(['customer_id', 'period']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deposit_limits');
    }
};
