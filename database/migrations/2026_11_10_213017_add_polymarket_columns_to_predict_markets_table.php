<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Imported-market fields: the event, what Polymarket last said (kept apart from the fields admins localise),
     * which fields an admin overrode (the sync leaves those alone), why a market is suspended, and rejection.
     */
    public function up(): void
    {
        Schema::table('predict_markets', function (Blueprint $table) {
            $table->foreignId('predict_event_id')->nullable()->after('source')->constrained('predict_events');
            $table->string('external_slug', 255)->nullable()->after('protocol_version');
            $table->string('external_question', 255)->nullable()->after('external_slug');
            $table->timestamp('external_end_at')->nullable()->after('external_question');
            $table->boolean('external_closed')->default(false)->after('external_end_at');
            $table->decimal('external_volume', 20, 2)->nullable()->after('external_closed');
            $table->decimal('external_liquidity', 20, 2)->nullable()->after('external_volume');
            $table->decimal('external_spread', 8, 6)->nullable()->after('external_liquidity');
            $table->timestamp('external_synced_at')->nullable()->after('external_spread');
            $table->json('overridden_fields')->nullable()->after('external_synced_at');
            $table->string('suspension_cause', 20)->nullable()->after('status');
            $table->timestamp('rejected_at')->nullable()->after('voided_at');
            $table->string('reject_reason', 255)->nullable()->after('rejected_at');
            $table->index(['source', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('predict_markets', function (Blueprint $table) {
            $table->dropIndex(['source', 'status']);
            $table->dropConstrainedForeignId('predict_event_id');
            $table->dropColumn([
                'external_slug', 'external_question', 'external_end_at', 'external_closed', 'external_volume',
                'external_liquidity', 'external_spread', 'external_synced_at', 'overridden_fields',
                'suspension_cause', 'rejected_at', 'reject_reason',
            ]);
        });
    }
};
