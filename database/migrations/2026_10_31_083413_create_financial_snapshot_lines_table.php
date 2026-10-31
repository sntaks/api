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
        Schema::create('financial_snapshot_lines', function (Blueprint $table) {
            $table->id();
            $table->date('snapshot_date');
            $table->foreignId('product_id')->nullable()->constrained('products');
            $table->string('account', 50);
            $table->decimal('amount', 14, 2);
            $table->timestamps();

            $table->unique(['snapshot_date', 'product_id', 'account'], 'uniq_snapshot_line');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('financial_snapshot_lines');
    }
};
