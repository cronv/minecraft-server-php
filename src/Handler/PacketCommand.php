<?php

declare(strict_types=1);

namespace Hub\Handler;

use Hub\Connection\ClientConnection;

/**
 * Command pattern: a received packet becomes a first-class, queueable,
 * testable object instead of an inline switch in the server loop.
 */
final class PacketCommand
{
    public function __construct(
        public readonly ClientConnection $connection,
        public readonly int $packetId,
        /** Frame bytes after the VarInt packet id. */
        public readonly string $payload,
    ) {
    }
}
