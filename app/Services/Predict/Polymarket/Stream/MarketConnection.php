<?php

namespace App\Services\Predict\Polymarket\Stream;

/**
 * A connection to Polymarket's market channel. `receive()` returns null when nothing arrived within its
 * timeout, and throws StreamDisconnected when the connection is gone.
 */
interface MarketConnection
{
    public function send(string $text): void;

    public function receive(): ?string;

    public function close(): void;
}
