<?php

declare(strict_types=1);

namespace Hub\Log;

use Hub\Protocol\PacketId;

/**
 * Console sink (stdout). Separate from FileLogger so sinks can be composed.
 */
final class ConsoleLogger implements Logger
{
    public function log(LogLevel $level, string $message): void
    {
        echo sprintf('%s %s: %s%s', $level->icon(), $level->value, $message, PHP_EOL);
    }

    public function logPacket(
        string $direction,
        int $clientId,
        int $packetId,
        string $payload,
    ): void {
        echo sprintf(
            '%s | Client %d | Packet 0x%02X (%s) | %d bytes%s',
            $direction,
            $clientId,
            $packetId,
            PacketId::nameFor($packetId),
            strlen($payload),
            PHP_EOL,
        );
    }
}
