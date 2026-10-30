<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wager_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products');
            $table->string('reference', 100);
            $table->string('status', 20)->default('open');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wager_rounds');
    }
};
