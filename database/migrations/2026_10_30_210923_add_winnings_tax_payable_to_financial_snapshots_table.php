<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('financial_snapshots', function (Blueprint $table) {
            $table->decimal('winnings_tax_payable', 14, 2)->default(0)->after('excise_duty_payable');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('financial_snapshots', function (Blueprint $table) {
            $table->dropColumn('winnings_tax_payable');
        });
    }
};
