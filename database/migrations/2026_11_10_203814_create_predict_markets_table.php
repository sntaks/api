<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Prediction markets: one binary (yes/no) question each. Money is in minor units (cents), rates in basis
     * points and multipliers in hundredths, so no money maths ever touches a float.
     */
    public function up(): void
    {
        Schema::create('predict_markets', function (Blueprint $table) {
            $table->id();
            $table->string('source', 20)->default('custom');
            // Polymarket identifiers (phase 1b); null for custom markets
            $table->string('external_market_id', 100)->nullable();
            $table->string('condition_id', 100)->nullable();
            $table->string('protocol_version', 10)->nullable();
            $table->string('question', 255);
            $table->text('description')->nullable();
            $table->text('resolution_criteria');
            $table->string('resolution_source', 255)->nullable();
            $table->string('category', 30);
            $table->string('image_url', 500)->nullable();
            $table->json('countries');
            $table->string('status', 20)->default('draft');
            $table->string('engine', 20)->default('pool');
            $table->foreignId('product_id')->constrained('products');
            $table->string('round_reference', 100)->unique();
            $table->timestamp('close_at');
            $table->timestamp('resolution_deadline_at');
            $table->string('result', 5)->nullable();
            $table->unsignedSmallInteger('cut_bp');
            $table->unsignedBigInteger('seed_minor');
            $table->unsignedSmallInteger('start_probability_bp');
            $table->unsignedBigInteger('min_stake_minor');
            $table->unsignedBigInteger('max_stake_minor');
            $table->unsignedBigInteger('max_payout_minor');
            $table->unsignedInteger('min_multiplier_x100');
            $table->string('created_by', 100);
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolved_by', 100)->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['source', 'external_market_id']);
            $table->index(['status', 'close_at']);
            $table->index(['category', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('predict_markets');
    }
};
