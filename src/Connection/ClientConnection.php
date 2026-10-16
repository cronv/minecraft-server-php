<?php

declare(strict_types=1);

namespace Hub\Connection;

use Hub\Protocol\ConnectionState;

/**
 * Strongly-typed connection aggregate.
 *
 * Replaces the legacy associative array `['socket' => …, 'state' => 'play', …]`
 * (string keys, no types, typo-prone, and every access copied the whole
 * array because getConnections() returned it by value).
 *
 * NOTE on memory: this object holds NO back-reference to the server or to any
 * manager, so there are no cycles — when a disconnect unsets the last strong
 * reference, the object (and its stream) is reclaimed immediately without
 * waiting for the GC cycle collector. The socket resource itself is released
 * deterministically in {@see self::close()}.
 */
final class ClientConnection
{
    /** @var resource|null */
    private $socket;

    private string $rxBuffer = '';

    private ConnectionState $state = ConnectionState::Handshake;

    private string $username = '';

    private string $uuid = '';

    private int $lastActivity;

    /** @var resource */
    private $socket;

    public function __construct($socket)
    {
        if (!is_resource($socket)) {
            throw new \InvalidArgumentException('Expected a valid stream resource.');
        }

        /** @var resource $socket */
        $this->socket = $socket;
        $this->lastActivity = time();
    }

    public function id(): int
    {
        return (int) $this->socket;
    }

    /** @return resource|null */
    public function socket()
    {
        return $this->socket;
    }

    public function isOpen(): bool
    {
        return is_resource($this->socket);
    }

    public function state(): ConnectionState
    {
        return $this->state;
    }

    public function setState(ConnectionState $state): void
    {
        $this->state = $state;
    }

    public function username(): string
    {
        return $this->username;
    }

    public function setUsername(string $username): void
    {
        $this->username = $username;
    }

    public function uuid(): string
    {
        return $this->uuid;
    }

    public function setUuid(string $uuid): void
    {
        $this->uuid = $uuid;
    }

    public function lastActivity(): int
    {
        return $this->lastActivity;
    }

    public function touch(): void
    {
        $this->lastActivity = time();
    }

    public function appendToBuffer(string $data): void
    {
        $this->rxBuffer .= $data;
    }

    /**
     * Consumes the buffered bytes, returning them and emptying the slot.
     * Passing by reference keeps this allocation-free for the caller.
     */
    public function &buffer(): string
    {
        return $this->rxBuffer;
    }

    public function markBufferConsumed(int $bytes): void
    {
        // Called after processBuffer() has shrunk the string itself.
        unset($bytes);
    }

    /**
     * Deterministic resource release — never rely on destructors for fds.
     */
    public function close(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }

        $this->socket = null;

        // Drop the accumulated bytes eagerly (large chunk bursts may sit here).
        $this->rxBuffer = '';
    }
}
