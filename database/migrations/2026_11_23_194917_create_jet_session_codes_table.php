<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One-time codes from POST /jet/launch: the browser trades one for its player token at POST /api/v1/jet/session.
     * Only the code's hash is kept; the token waits encrypted and is wiped when the code is used.
     */
    public function up(): void
    {
        Schema::create('jet_session_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code_hash', 64)->unique();
            $table->foreignId('customer_id')->constrained('customers');
            $table->text('token_ciphertext')->nullable();
            $table->timestamp('token_expires_at');
            $table->timestamp('expires_at')->index();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jet_session_codes');
    }
};
