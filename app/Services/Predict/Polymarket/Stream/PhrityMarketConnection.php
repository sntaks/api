<?php

namespace App\Services\Predict\Polymarket\Stream;

use Throwable;
use WebSocket\Client;
use WebSocket\Exception\ConnectionTimeoutException;
use WebSocket\Message\Text;

/**
 * The market channel over phrity/websocket.
 */
class PhrityMarketConnection implements MarketConnection
{
    private Client $client;

    public function __construct(string $url, float $receiveTimeout = 1.0)
    {
        try {
            $this->client = new Client($url);
            $this->client->setTimeout($receiveTimeout);
            $this->client->connect();
        } catch (Throwable $e) {
            throw new StreamDisconnected('Could not connect to the market channel: '.$e->getMessage(), 0, $e);
        }
    }

    public function send(string $text): void
    {
        try {
            $this->client->text($text);
        } catch (Throwable $e) {
            throw new StreamDisconnected('Send failed: '.$e->getMessage(), 0, $e);
        }
    }

    public function receive(): ?string
    {
        try {
            $message = $this->client->receive();
        } catch (ConnectionTimeoutException) {
            return null;
        } catch (Throwable $e) {
            throw new StreamDisconnected('Receive failed: '.$e->getMessage(), 0, $e);
        }

        return $message instanceof Text ? $message->getContent() : null;
    }

    public function close(): void
    {
        try {
            $this->client->close();
        } catch (Throwable) {
            // Already gone
        }
    }
}
