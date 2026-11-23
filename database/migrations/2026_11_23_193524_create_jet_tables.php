<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kadi Jet: the crash engine ported from the crash server. Money lives in wagers; these tables hold the
     * rounds, the bets' game state, the contract fence (revisions, loop lease), operations and the outbox.
     */
    public function up(): void
    {
        Schema::create('jet_contract_state', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedBigInteger('revision')->default(0);
            $table->string('loop_owner')->nullable();
            $table->timestamp('lease_until', 6)->nullable();
        });

        DB::table('jet_contract_state')->insert(['id' => 1, 'revision' => 0]);

        Schema::create('jet_rounds', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('round_number')->unique();
            $table->string('status', 20)->index();
            $table->unsignedBigInteger('revision')->default(0);
            $table->string('server_seed');
            $table->string('server_seed_hash', 64);
            $table->string('client_seed');
            $table->unsignedBigInteger('nonce');
            $table->decimal('house_edge', 10, 8);
            $table->decimal('max_multiplier_cap', 10, 2);
            $table->decimal('acceleration_k', 12, 8);
            $table->decimal('crash_multiplier', 10, 2);
            $table->timestamp('betting_closes_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('crashed_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->string('voided_by')->nullable();
            $table->timestamps();
        });

        Schema::create('jet_bets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jet_round_id')->constrained('jet_rounds');
            $table->foreignId('customer_id')->constrained('customers');
            $table->foreignId('wager_id')->nullable()->constrained('wagers');
            $table->string('wager_reference', 64)->unique();
            $table->string('channel', 20);
            $table->decimal('stake', 14, 2);
            $table->decimal('auto_cashout', 10, 2)->nullable();
            $table->decimal('cap_multiplier', 10, 2);
            $table->decimal('cashout_multiplier', 10, 2)->nullable();
            $table->decimal('payout', 14, 2)->nullable();
            $table->decimal('tax', 14, 2)->nullable();
            $table->decimal('net_payout', 14, 2)->nullable();
            $table->string('status', 20);
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestamp('placed_at');
            $table->timestamp('cashed_out_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->index(['jet_round_id', 'status']);
            $table->index(['customer_id', 'status']);
        });

        Schema::create('jet_private_streams', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_id')->primary();
            $table->unsignedBigInteger('revision')->default(0);
        });

        Schema::create('jet_operations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->string('key_hash', 64);
            $table->string('fingerprint', 64);
            $table->json('result')->nullable();
            $table->timestamp('created_at');

            $table->unique(['customer_id', 'key_hash']);
            $table->index('created_at');
        });

        Schema::create('jet_outbox', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('stream');
            $table->unsignedBigInteger('revision');
            $table->json('envelope');
            $table->timestamp('published_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jet_outbox');
        Schema::dropIfExists('jet_operations');
        Schema::dropIfExists('jet_private_streams');
        Schema::dropIfExists('jet_bets');
        Schema::dropIfExists('jet_rounds');
        Schema::dropIfExists('jet_contract_state');
    }
};
