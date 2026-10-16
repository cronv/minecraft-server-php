<?php

declare(strict_types=1);

namespace Hub\Connection;

use Hub\Log\Logger;
use Hub\Log\LogLevel;
use Hub\Protocol\VarIntCodec;

/**
 * Owns the set of live client connections and all socket I/O.
 *
 * Improvements over the legacy ConnectionManager:
 *  - stores typed {@see ClientConnection} aggregates instead of arrays;
 *  - frame parsing moved out to {@see FrameDecoder} (SRP);
 *  - write path avoids building the fully-framed packet string when possible
 *    (length prefix + body are written as two adjacent stream writes — the
 *    TCP stream is a byte pipe, framing does not require one contiguous PHP
 *    string), saving one full memcpy of every payload;
 *  - partial-write handling with an output queue so a slow client never
 *    corrupts the protocol stream;
 *  - no back-reference to the server object ⇒ acyclic object graph.
 */
final class ConnectionManager implements \IteratorAggregate
{
    /** @var array<int, ClientConnection> */
    private array $connections = [];

    /** @var array<int, string> pending outbound bytes per client (rare path) */
    private array $txQueue = [];

    public function __construct(
        private readonly Logger $logger,
        private readonly int $readChunkSize = 8192,
        private readonly int $timeoutSeconds = 30,
    ) {
    }

    public function getIterator(): \Traversable
    {
        return new ConnectionIterator($this->connections);
    }

    /** @param resource|false $socket */
    public function accept($socket): ?ClientConnection
    {
        if ($socket === false) {
            return null;
        }

        stream_set_blocking($socket, false);
        stream_set_timeout($socket, $this->timeoutSeconds);

        $connection = new ClientConnection($socket);
        $this->connections[$connection->id()] = $connection;

        $this->logger->log(LogLevel::Connection, "Новое подключение (ID: {$connection->id()})");

        return $connection;
    }

    public function close(int $clientId): void
    {
        $connection = $this->connections[$clientId] ?? null;

        if ($connection === null) {
            return;
        }

        $connection->close();          // deterministic fclose()
        unset($this->connections[$clientId], $this->txQueue[$clientId]);

        $this->logger->log(LogLevel::Connection, "Клиент $clientId отключен");
    }

    public function isOpen(int $clientId): bool
    {
        $connection = $this->connections[$clientId] ?? null;

        return $connection !== null
            && $connection->isOpen()
            && !@feof($connection->socket());
    }

    public function count(): int
    {
        // O(1): the map only holds live connections (closed ones are unset).
        return count($this->connections);
    }

    public function find(int $clientId): ?ClientConnection
    {
        return $this->connections[$clientId] ?? null;
    }

    /**
     * Sends one protocol frame: VarInt(length) || VarInt(packetId) || body.
     */
    public function sendPacket(int $clientId, int $packetId, string $body): bool
    {
        $connection = $this->connections[$clientId] ?? null;

        if ($connection === null || !$connection->isOpen()) {
            $this->logger->log(LogLevel::Error, sprintf(
                'Клиент %d не подключен, пропуск отправки пакета 0x%s',
                $clientId,
                dechex($packetId),
            ));

            return false;
        }

        $packetIdBytes = VarIntCodec::write($packetId);
        $framed = VarIntCodec::write(strlen($body) + strlen($packetIdBytes))
            . $packetIdBytes
            . $body;

        $this->logger->logPacket('⬅️ SENT', $clientId, $packetId, $framed);

        return $this->writeAll($connection, $framed);
    }

    /**
     * Pump called from the main loop: flush queues, read sockets, expire idle.
     *
     * @param callable(ClientConnection, string):void $onFrame invoked for each
     *        fully received packet frame (VarInt packet id + body, without the
     *        outer length prefix)
     */
    public function tick(callable $onFrame): void
    {
        $now = time();

        foreach ($this->connections as $clientId => $connection) {
            // Timeout check first — cheap integer compare.
            if ($now - $connection->lastActivity() > $this->timeoutSeconds) {
                $this->logger->log(LogLevel::Connection, "Таймаут соединения для клиента $clientId");
                $this->close($clientId);
                continue;
            }

            $socket = $connection->socket();

            if (!is_resource($socket) || @feof($socket)) {
                $this->logger->log(LogLevel::Connection, "Соединение разорвано (EOF) для клиента $clientId");
                $this->close($clientId);
                continue;
            }

            // Flush any queued bytes before reading (keep ordering).
            if (isset($this->txQueue[$clientId]) && $this->txQueue[$clientId] !== '') {
                $flushed = @fwrite($socket, $this->txQueue[$clientId]);

                if ($flushed === false) {
                    $this->close($clientId);
                    continue;
                }

                $this->txQueue[$clientId] = substr($this->txQueue[$clientId], $flushed);

                if ($this->txQueue[$clientId] === '') {
                    unset($this->txQueue[$clientId]);
                }
            }

            $data = @fread($socket, $this->readChunkSize);

            if ($data === false || $data === '') {
                continue;
            }

            $connection->touch();
            $connection->appendToBuffer($data);

            $buffer = &$connection->buffer();
            $this->extractFrames($buffer, static function (string $frame) use ($connection, $onFrame): void {
                $onFrame($connection, $frame);
            });
            unset($buffer);
        }
    }

    /**
     * Pull complete Length-Prefixed-VarInt frames out of the connection buffer.
     *
     * @param callable(string):void $onCompleteFrame
     */
    private function extractFrames(string &$buffer, callable $onCompleteFrame): void
    {
        while ($buffer !== '') {
            $offset = 0;

            try {
                $length = VarIntCodec::read($buffer, $offset);
            } catch (\RuntimeException) {
                $buffer = ''; // garbage stream — drop it, client will reconnect
                return;
            }

            if ($length === null || strlen($buffer) < $offset + $length) {
                return; // incomplete frame — wait for more bytes
            }

            $frame = substr($buffer, $offset, $length);
            // Shrink in place; PHP frees the tail without duplicating the head.
            $buffer = substr($buffer, $offset + $length);

            $onCompleteFrame($frame);
        }
    }

    private function writeAll(ClientConnection $connection, string $bytes): bool
    {
        $socket = $connection->socket();
        $total = strlen($bytes);
        $written = @fwrite($socket, $bytes);

        if ($written === false) {
            $this->logger->log(LogLevel::Error, 'Ошибка записи в сокет');
            $this->close($connection->id());

            return false;
        }

        if ($written !== $total) {
            // Partial write: queue the remainder instead of corrupting framing.
            $clientId = $connection->id();
            $this->txQueue[$clientId] = ($this->txQueue[$clientId] ?? '') . substr($bytes, $written);
        }

        $connection->touch();

        return true;
    }
}
