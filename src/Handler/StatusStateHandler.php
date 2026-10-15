<?php

declare(strict_types=1);

namespace Hub\Handler;

use Hub\Config\ServerConfig;
use Hub\Connection\ConnectionManager;
use Hub\Log\Logger;
use Hub\Log\LogLevel;
use Hub\Packet\PacketFactory;
use Hub\Protocol\PacketBuffer;
use Hub\Protocol\PacketId;

/**
 * Status state: answers pings and server-list queries.
 */
final class StatusStateHandler implements StateHandler
{
    /** @var \Closure(mixed, int): string|false First-class callable of json_encode(). */
    private readonly \Closure $jsonEncoder;

    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly ServerConfig $config,
        private readonly Logger $logger,
        private readonly \Closure $onlineCounter,
    ) {
        // First-class callable syntax: partial application of json_encode().
        $this->jsonEncoder = json_encode(...);
    }

    public function handle(PacketCommand $command): void
    {
        $buffer = new PacketBuffer($command->payload);
        $packetId = $buffer->readVarInt() ?? -1;
        $clientId = $command->connection->id();

        match ($packetId) {
            PacketId::StatusResponse->value => $this->respondStatus($clientId),
            PacketId::PingResponse->value   => $this->respondPong($clientId, $buffer),
            default => null,
        };
    }

    private function respondStatus(int $clientId): void
    {
        $encoder = $this->jsonEncoder;

        $body = PacketFactory::statusResponse(
            config: $this->config,
            onlinePlayers: ($this->onlineCounter)(),
            jsonEncoder: static fn (array $payload): string => (string) $encoder(
                $payload,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ),
        );

        $this->connections->sendPacket($clientId, PacketId::StatusResponse->value, $body);
        $this->logger->log(LogLevel::Info, "Отправлен статус сервера клиенту $clientId");
    }

    private function respondPong(int $clientId, PacketBuffer $buffer): void
    {
        $timestamp = $buffer->has(8) ? $buffer->readInt64() : time();

        $this->connections->sendPacket(
            $clientId,
            PacketId::PingResponse->value,
            PacketFactory::keepAlive($timestamp),
        );

        $this->logger->log(LogLevel::Info, "Ping/Pong завершен для клиента $clientId");
    }
}
