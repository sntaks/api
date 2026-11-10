<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only log of every admin action on prediction markets.
     */
    public function up(): void
    {
        Schema::create('predict_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('predict_market_id')->nullable()->constrained('predict_markets');
            $table->string('action', 40);
            $table->string('admin_user', 100);
            $table->string('reason', 255)->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->foreignId('api_key_id')->nullable()->constrained('api_keys');
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['predict_market_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('predict_audit_logs');
    }
};
