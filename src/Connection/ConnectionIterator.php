<?php

declare(strict_types=1);

namespace Hub\Connection;

/**
 * Iterator Aggregate: exposes connections without copying the internal map.
 *
 * The legacy `getConnections(): array` returned the whole array BY VALUE —
 * every keep-alive tick duplicated all connection data (including open stream
 * handles) into a fresh zval table. Iterating by reference over an object
 * graph is allocation-free and cannot go stale mid-loop.
 */
final class ConnectionIterator implements \IteratorAggregate
{
    /** @param \Traversable<int, ClientConnection> $connections */
    public function __construct(private readonly \Traversable $connections)
    {
    }

    public function getIterator(): \Generator
    {
        foreach ($this->connections as $id => $connection) {
            yield $id => $connection;
        }
    }
}
