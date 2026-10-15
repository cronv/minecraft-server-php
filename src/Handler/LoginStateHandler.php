<?php

declare(strict_types=1);

namespace Hub\Handler;

use Hub\Connection\ConnectionManager;
use Hub\Log\Logger;
use Hub\Log\LogLevel;
use Hub\Protocol\BinaryStream;
use Hub\Protocol\ConnectionState;
use Hub\Protocol\PacketBuffer;
use Hub\Protocol\PacketId;
use Hub\Protocol\Uuid;
use Hub\Server\GameSessionInitiator;

/**
 * Login state: accepts the player, replies Login Success and hands the
 * connection to the session initiator (Mediator) for the join sequence.
 */
final class LoginStateHandler implements StateHandler
{
    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly Logger $logger,
        private readonly GameSessionInitiator $session,
    ) {
    }

    public function handle(PacketCommand $command): void
    {
        $buffer = new PacketBuffer($command->payload);
        $packetId = $buffer->readVarInt() ?? -1;

        if ($packetId !== 0x00) { // only Login Start is defined here
            return;
        }

        $connection = $command->connection;
        $username   = $buffer->readString();

        if ($username === '' || strlen($username) > 16) {
            $this->logger->log(LogLevel::Error, "Некорректное имя игрока от клиента {$connection->id()}");
            $this->connections->close($connection->id());

            return;
        }

        $connection->setUsername($username);
        $uuid = Uuid::v4();
        $connection->setUuid($uuid);

        $this->logger->log(LogLevel::Info, "Login Start: $username (клиент {$connection->id()})");

        // Login Success body: UUID (16 bytes) + username (protocol String)
        $body = Uuid::toBinary($uuid) . (function () use ($username): string {
            $b = new \Hub\Protocol\BinaryStream();
            $b->writeString($username);

            return $b->contents();
        })();

        $this->connections->sendPacket($connection->id(), PacketId::LoginSuccess->value, $body);
        $this->logger->log(LogLevel::Info, "Login Success отправлен клиенту {$connection->id()}");

        $connection->setState(ConnectionState::Play);
        $this->session->initialize($connection);
    }
}
