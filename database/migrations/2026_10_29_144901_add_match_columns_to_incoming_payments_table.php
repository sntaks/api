<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a paybill deposit's bill ref was matched to a customer (account_no, player_no, phone or
 * phone_other_payer), or why it was not (unknown, phone_ambiguous, phone_unverified). Older rows stay null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incoming_payments', function (Blueprint $table) {
            $table->string('matched_by', 20)->nullable()->after('status');
            $table->string('unmatched_reason', 30)->nullable()->after('matched_by');
            $table->index('matched_by', 'idx_incoming_payments_matched_by');
        });
    }

    public function down(): void
    {
        Schema::table('incoming_payments', function (Blueprint $table) {
            $table->dropIndex('idx_incoming_payments_matched_by');
            $table->dropColumn(['matched_by', 'unmatched_reason']);
        });
    }
};
