<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Events imported from Polymarket: an event groups one or more binary markets.
     */
    public function up(): void
    {
        Schema::create('predict_events', function (Blueprint $table) {
            $table->id();
            $table->string('source', 20);
            $table->string('external_event_id', 100);
            $table->string('slug', 255)->nullable();
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('image_url', 500)->nullable();
            $table->string('category', 30);
            $table->json('tags')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_closed')->default(false);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['source', 'external_event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('predict_events');
    }
};
