<?php

namespace App\Domain\Jet;

use App\Events\JetV1Event;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Snapshot invalidations through an outbox written in the same transaction as the change (call it inside
 * JetLock). Public changes advance jet_contract_state.revision; a player's own changes advance their private
 * stream. Envelopes carry revision watermarks only, never balances or results.
 */
class EventPublisher
{
    public const PUBLIC_STREAM = 'jet.rounds';

    public static function playerStream(int $customerId): string
    {
        return "jet.player.{$customerId}";
    }

    public static function changed(?int $customerId = null): int
    {
        if ($customerId === null) {
            $stream = self::PUBLIC_STREAM;
            DB::table('jet_contract_state')->where('id', 1)->increment('revision');
            $revision = (int) DB::table('jet_contract_state')->where('id', 1)->value('revision');
        } else {
            $stream = self::playerStream($customerId);
            DB::table('jet_private_streams')->insertOrIgnore(['customer_id' => $customerId, 'revision' => 0]);
            DB::table('jet_private_streams')->where('customer_id', $customerId)->increment('revision');
            $revision = (int) DB::table('jet_private_streams')->where('customer_id', $customerId)->value('revision');
        }

        $envelope = [
            'schema_version' => 1,
            'event_id' => (string) Str::uuid(),
            'occurred_at' => now()->toISOString(),
            'stream' => $stream,
            'revision' => $revision,
            'type' => 'snapshot.invalidated',
            'payload' => [],
        ];

        DB::table('jet_outbox')->insert([
            'id' => $envelope['event_id'],
            'stream' => $stream,
            'revision' => $revision,
            'envelope' => json_encode($envelope, JSON_THROW_ON_ERROR),
        ]);

        return $revision;
    }

    /**
     * Queues up to $limit pending invalidations for broadcast. A crash between queueing and marking can send
     * one twice; clients deduplicate by event id and revision.
     */
    public function flush(int $limit = 100): int
    {
        $rows = DB::table('jet_outbox')->whereNull('published_at')->orderBy('stream')->orderBy('revision')->limit($limit)->get();

        foreach ($rows as $row) {
            event(new JetV1Event(json_decode($row->envelope, true, 512, JSON_THROW_ON_ERROR)));
            DB::table('jet_outbox')->where('id', $row->id)->update(['published_at' => now()]);
        }

        return $rows->count();
    }
}
