<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

/**
 * The one Kadi Jet realtime event (jet.v1): a snapshot invalidation envelope from the outbox, broadcast through
 * the queue on public jet.rounds or private jet.player.{customer_id}.
 */
class JetV1Event implements ShouldBroadcast
{
    /**
     * @param  array<string, mixed>  $envelope
     */
    public function __construct(public readonly array $envelope) {}

    /**
     * @return list<Channel>
     */
    public function broadcastOn(): array
    {
        $stream = $this->envelope['stream'];

        return [str_starts_with($stream, 'jet.player.') ? new PrivateChannel($stream) : new Channel($stream)];
    }

    public function broadcastAs(): string
    {
        return 'jet.v1';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->envelope;
    }
}
