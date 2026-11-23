<?php

namespace App\Domain\Jet;

use Illuminate\Support\Facades\DB;

/**
 * The round parameters GMS may change (betting window, post-round pause, max multiplier), stored in jet_settings
 * and falling back to config/jet.php. House edge is config only: an RTP change is an owner decision. A round
 * freezes its parameters when it is created, so a change applies from the next round.
 */
class JetSettings
{
    public const EDITABLE = ['betting_window_seconds', 'post_round_pause_seconds', 'max_multiplier'];

    /**
     * @return array{house_edge: string, betting_window_seconds: int, post_round_pause_seconds: int, max_multiplier: string, acceleration_k: string, updated_by: string|null, updated_at: string|null}
     */
    public function current(): array
    {
        $row = DB::table('jet_settings')->where('id', 1)->first();

        return [
            'house_edge' => number_format((float) config('jet.house_edge'), 4, '.', ''),
            'betting_window_seconds' => (int) ($row->betting_window_seconds ?? config('jet.betting_window_seconds')),
            'post_round_pause_seconds' => (int) ($row->post_round_pause_seconds ?? config('jet.post_round_pause_seconds')),
            'max_multiplier' => number_format((float) ($row->max_multiplier ?? config('jet.max_multiplier')), 2, '.', ''),
            'acceleration_k' => (string) config('jet.acceleration_k'),
            'updated_by' => $row->updated_by ?? null,
            'updated_at' => $row->updated_at ?? null,
        ];
    }

    /**
     * @param  array<string, int|string>  $changes  only EDITABLE keys
     */
    public function update(array $changes, string $adminUser): void
    {
        $values = [...array_intersect_key($changes, array_flip(self::EDITABLE)), 'updated_by' => $adminUser, 'updated_at' => now()];

        if (DB::table('jet_settings')->where('id', 1)->exists()) {
            DB::table('jet_settings')->where('id', 1)->update($values);
        } else {
            DB::table('jet_settings')->insert(['id' => 1, ...$values, 'created_at' => now()]);
        }
    }
}
