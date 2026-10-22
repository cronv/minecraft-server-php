<?php

declare(strict_types=1);

namespace Hub\Handler;

use Hub\Connection\ConnectionManager;
use Hub\Log\Logger;
use Hub\Log\LogLevel;
use Hub\Protocol\ConnectionState;
use Hub\Protocol\PacketBuffer;

/**
 * Handshake state: reads protocol version / address / next state and
 * transitions the connection (State pattern).
 */
final class HandshakeStateHandler implements StateHandler
{
    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly Logger $logger,
    ) {
    }

    public function handle(PacketCommand $command): void
    {
        $buffer = new PacketBuffer($command->payload);

        // Handshake body: VarInt protocol, String address, UShort port, VarInt next state
        $protocolVersion = $buffer->readVarInt();
        $serverAddress   = $buffer->readString();
        $serverPort      = $buffer->has(2) ? $buffer->readUInt16() : 0;
        $nextState       = $buffer->readVarInt();

        $this->logger->log(LogLevel::Info, sprintf(
            'Handshake: protocol=%s, nextState=%s, address=%s:%s',
            (string) $protocolVersion,
            (string) $nextState,
            $serverAddress,
            (string) $serverPort,
        ));

        $state = ConnectionState::fromNextState($nextState ?? 0);

        if ($state !== null) {
            $command->connection->setState($state);
        } else {
            $this->logger->log(LogLevel::Error, "Неизвестный next_state: " . (string) $nextState);
            $this->connections->close($command->connection->id());
        }
    }
}
