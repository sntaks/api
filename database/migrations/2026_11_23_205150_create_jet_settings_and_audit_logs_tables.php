<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kadi Jet GMS settings (one row; a null column falls back to config/jet.php) and the audit log of every
     * admin change: settings updates and round voids.
     */
    public function up(): void
    {
        Schema::create('jet_settings', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('betting_window_seconds')->nullable();
            $table->unsignedInteger('post_round_pause_seconds')->nullable();
            $table->decimal('max_multiplier', 10, 2)->nullable();
            $table->string('updated_by', 100)->nullable();
            $table->timestamps();
        });

        Schema::create('jet_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('action', 40);
            $table->unsignedBigInteger('round_number')->nullable()->index();
            $table->string('admin_user', 100);
            $table->string('reason', 255)->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->foreignId('api_key_id')->nullable()->constrained('api_keys');
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jet_audit_logs');
        Schema::dropIfExists('jet_settings');
    }
};
