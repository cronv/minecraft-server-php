<?php

declare(strict_types=1);

namespace Hub\Log;

/**
 * Decorator: fans one log record out to many sinks (Composite-ish behavior
 * behind the Logger contract). Composable, e.g.
 *
 *   $logger = new MultiLogger(new FileLogger('packets.log'), new ConsoleLogger());
 */
final class MultiLogger implements Logger
{
    /** @var list<Logger> */
    private readonly array $sinks;

    public function __construct(Logger ...$sinks)
    {
        $this->sinks = $sinks;
    }

    public function log(LogLevel $level, string $message): void
    {
        foreach ($this->sinks as $sink) {
            $sink->log($level, $message);
        }
    }

    public function logPacket(
        string $direction,
        int $clientId,
        int $packetId,
        string $payload,
    ): void {
        foreach ($this->sinks as $sink) {
            $sink->logPacket($direction, $clientId, $packetId, $payload);
        }
    }
}
