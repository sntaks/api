<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Breaks from play a player took (cool-off or self-exclusion). Append-only: the running break is the row
     * with the latest ends_at, when that is still in the future.
     */
    public function up(): void
    {
        Schema::create('customer_exclusions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('duration', 5);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('actor', 100)->nullable();
            // Who the break was for, kept even if the account later changes them: a new or changed account
            // with the same phone or national ID inherits the break.
            $table->char('phone_hash', 64)->nullable()->index();
            $table->string('id_no', 50)->nullable()->index();
            $table->timestamps();

            $table->index(['customer_id', 'ends_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_exclusions');
    }
};
