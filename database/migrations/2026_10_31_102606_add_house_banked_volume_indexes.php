<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Indexes for house-banked volume (slots and scratch cards settle one wager per spin or card), checked
     * against 200,000 seeded wagers and 1,000,000 ledger entries:
     *  - the house wallet's wager lines in the income statement and /finance/house-banked read only the
     *    entry types and dates they need instead of every house wallet entry;
     *  - /finance/house-banked counts settlements by the day they were made and the day they were reversed;
     *  - the aged_open_wagers and settled_wagers_with_balance reconciliation checks read only matching wagers.
     *
     * @var array<string, array<string, list<string>>>
     */
    private array $indexes = [
        'ledger_entries' => [
            'idx_ledger_entries_wallet_type_created' => ['wallet_type', 'wallet_id', 'entry_type', 'created_at'],
        ],
        'wager_settlements' => [
            'idx_wager_settlements_created_at' => ['created_at'],
            'idx_wager_settlements_reversed_at' => ['reversed_at'],
        ],
        'wagers' => [
            'idx_wagers_status_placed_at' => ['status', 'placed_at'],
            'idx_wagers_status_balance' => ['status', 'balance'],
        ],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $table => $indexes) {
            Schema::table($table, function (Blueprint $blueprint) use ($table, $indexes) {
                foreach ($indexes as $name => $columns) {
                    if (! Schema::hasIndex($table, $name)) {
                        $blueprint->index($columns, $name);
                    }
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $table => $indexes) {
            Schema::table($table, function (Blueprint $blueprint) use ($table, $indexes) {
                foreach (array_keys($indexes) as $name) {
                    if (Schema::hasIndex($table, $name)) {
                        $blueprint->dropIndex($name);
                    }
                }
            });
        }
    }
};
