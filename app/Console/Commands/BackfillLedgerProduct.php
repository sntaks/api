<?php

namespace App\Console\Commands;

use App\Models\LedgerEntry;
use App\Models\Product;
use App\Services\ProductResolver;
use Illuminate\Console\Command;

class BackfillLedgerProduct extends Command
{
    protected $signature = 'ledger:backfill-product {--dry-run : Report what would change without writing}';

    protected $description = 'Fill ledger_entries.product_id for entries written before the column existed';

    public function handle(ProductResolver $products): int
    {
        $dryRun = (bool) $this->option('dry-run');

        /** @var array<int|string, int> $counts product id (or 'platform') => entries */
        $counts = [];
        $unplaced = [];

        // Reversals take the product of the entry they reverse, so tag everything else first.
        foreach ([false, true] as $reversals) {
            LedgerEntry::whereNull('product_id')
                ->where('entry_type', $reversals ? 'like' : 'not like', '%_reversal')
                ->chunkById(1000, function ($entries) use ($products, $dryRun, &$counts, &$unplaced) {
                    $idsByProduct = [];

                    foreach ($entries as $entry) {
                        $productId = $products->forStoredEntry($entry);

                        // Every house cut is game revenue: one that cannot be placed is a gap, not platform.
                        if ($productId === null && $entry->entry_type === 'house_cut') {
                            $unplaced[] = [$entry->id, $entry->metadata['source'] ?? '', $entry->credit, $entry->created_at];

                            continue;
                        }

                        $counts[$productId ?? 'platform'] = ($counts[$productId ?? 'platform'] ?? 0) + 1;

                        if ($productId !== null) {
                            $idsByProduct[$productId][] = $entry->id;
                        }
                    }

                    if ($dryRun) {
                        return;
                    }

                    // toBase() leaves updated_at alone: the entry itself did not change.
                    foreach ($idsByProduct as $productId => $ids) {
                        LedgerEntry::whereIn('id', $ids)->toBase()->update(['product_id' => $productId]);
                    }
                });
        }

        $codes = Product::pluck('code', 'id');

        $this->info($dryRun ? 'Dry run, nothing was written.' : 'Backfill complete.');
        $this->table(
            ['product', 'entries'],
            collect($counts)
                ->except('platform')
                ->map(fn (int $count, int $productId) => [$codes[$productId] ?? "#{$productId}", $count])
                ->values()
                ->all()
        );
        $this->line(($counts['platform'] ?? 0).' entries left as platform (no product).');

        if ($unplaced !== []) {
            $this->warn(count($unplaced).' house cuts could not be placed and were left null:');
            $this->table(['id', 'source', 'amount', 'created_at'], array_slice($unplaced, 0, 50));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
