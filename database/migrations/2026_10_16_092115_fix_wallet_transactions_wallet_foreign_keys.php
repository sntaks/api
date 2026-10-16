<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `sender_id` holds a competition wallet id on competition house cuts (c2w), not only customer wallet
     * ids, so it cannot reference `wallets`. Databases that dropped that key by hand also lost the
     * `receiver_id` key, which is valid (every receiver is a wallet), so it is put back where missing.
     */
    public function up(): void
    {
        $foreignKeyColumns = $this->foreignKeyColumns();

        Schema::table('wallet_transactions', function (Blueprint $table) use ($foreignKeyColumns) {
            if (in_array('sender_id', $foreignKeyColumns, true)) {
                $table->dropForeign(['sender_id']);
            }

            if (! in_array('receiver_id', $foreignKeyColumns, true)) {
                $table->foreign('receiver_id')->references('id')->on('wallets')->onDelete('set null');
            }
        });
    }

    /**
     * The sender key is not restored: competition house cuts written since would break it.
     */
    public function down(): void {}

    /**
     * @return list<string>
     */
    private function foreignKeyColumns(): array
    {
        return collect(Schema::getForeignKeys('wallet_transactions'))
            ->flatMap(fn (array $foreignKey) => $foreignKey['columns'])
            ->values()
            ->all();
    }
};
