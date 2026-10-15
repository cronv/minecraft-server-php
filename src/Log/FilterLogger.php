<?php

declare(strict_types=1);

namespace Hub\Log;

/**
 * Decorator (Proxy for cost control): drops noisy levels unless enabled.
 *
 * The legacy server unconditionally hex-dumped EVERY packet on every read and
 * write — for chunk data (~500 B+) this was pure waste in production. A
 * FilterLogger short-circuits before any formatting work happens, so disabled
 * levels cost a single enum comparison.
 */
final class FilterLogger implements Logger
{
    /** @param array<LogLevel, bool>|null $enabled null ⇒ everything enabled */
    public function __construct(
        private readonly Logger $inner,
        private readonly ?array $enabled = null,
    ) {
    }

    public function log(LogLevel $level, string $message): void
    {
        if ($this->accept($level)) {
            $this->inner->log($level, $message);
        }
    }

    public function logPacket(
        string $direction,
        int $clientId,
        int $packetId,
        string $payload,
    ): void {
        if ($this->accept(LogLevel::Packet)) {
            $this->inner->logPacket($direction, $clientId, $packetId, $payload);
        }
    }

    private function accept(LogLevel $level): bool
    {
        return $this->enabled === null || ($this->enabled[$level] ?? false);
    }
}
