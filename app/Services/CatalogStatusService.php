<?php

namespace App\Services;

use App\Models\CatalogStatusChange;
use App\Models\Game;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * GMS edits of a game or a product. A status change is kept with its reason and admin in catalog_status_changes.
 */
class CatalogStatusService
{
    /**
     * @param  array<string, mixed>  $data  validated fields, with reason and admin_user when the status is sent
     */
    public function update(Game|Product $subject, array $data, ?string $actor): Game|Product
    {
        return DB::transaction(function () use ($subject, $data, $actor): Game|Product {
            $locked = $subject->newQuery()->lockForUpdate()->findOrFail($subject->getKey());
            $from = $locked->status;

            $locked->update(collect($data)->except(['reason', 'admin_user'])->all());

            if ($locked->status !== $from) {
                CatalogStatusChange::create([
                    'subject_type' => $subject instanceof Game ? CatalogStatusChange::SUBJECT_GAME : CatalogStatusChange::SUBJECT_PRODUCT,
                    'subject_id' => $locked->getKey(),
                    'from_status' => $from,
                    'to_status' => $locked->status,
                    'reason' => $data['reason'],
                    'actor' => $actor,
                    'admin_user' => $data['admin_user'],
                ]);
            }

            return $locked;
        });
    }
}
