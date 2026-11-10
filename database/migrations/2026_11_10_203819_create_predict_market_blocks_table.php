<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Customers an admin barred from one market (people involved in the event, insiders).
     */
    public function up(): void
    {
        Schema::create('predict_market_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('predict_market_id')->constrained('predict_markets');
            $table->foreignId('customer_id')->constrained('customers');
            $table->string('reason', 255);
            $table->string('admin_user', 100);
            $table->timestamps();

            $table->unique(['predict_market_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('predict_market_blocks');
    }
};
