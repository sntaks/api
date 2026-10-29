<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The game dimension: the product an entry belongs to, or null for platform entries. Older rows are
     * filled by ledger:backfill-product.
     */
    public function up(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->after('customer_id')->constrained()->nullOnDelete();
            $table->index(['product_id', 'entry_type', 'created_at'], 'idx_ledger_entries_product_type_created');
        });
    }

    public function down(): void
    {
        // Drop the index by name before the column: MySQL would otherwise keep it as (entry_type, created_at)
        // under the same name, and migrating again would fail on a duplicate key name.
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
            $table->dropIndex('idx_ledger_entries_product_type_created');
            $table->dropColumn('product_id');
        });
    }
};
