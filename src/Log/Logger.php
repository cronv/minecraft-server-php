<?php

declare(strict_types=1);

namespace Hub\Log;

use Hub\Protocol\PacketId;

/**
 * Logger contract — lets us decorate / swap sinks without touching callers
 * (Open/Closed, and the basis for the Decorator chain below).
 */
interface Logger
{
    public function log(LogLevel $level, string $message): void;

    /**
     * Structured packet dump (hex view), hot path of the server.
     */
    public function logPacket(
        string $direction,
        int $clientId,
        int $packetId,
        string $payload,
    ): void;
}
