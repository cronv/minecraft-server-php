<?php

declare(strict_types=1);

namespace Hub\Log;

/**
 * File sink: keeps ONE persistent handle open for the process lifetime.
 *
 * Replaces `file_put_contents(..., FILE_APPEND)` which on every single log
 * line performed: open() + lseek(EOF) + write() + close() — 3 syscalls plus
 * PHP stream-bucket allocation per packet. With hundreds of packets per
 * second this dominated I/O cost and produced allocator churn.
 */
final class FileLogger implements Logger
{
    /** @var resource|null lazily opened (Lazy Loading): no fd until first write */
    private $handle = null;

    public function __construct(
        private readonly string $path,
        private readonly bool $truncateOnOpen = false,
    ) {
    }

    public function __destruct()
    {
        $this->close();
    }

    public function log(LogLevel $level, string $message): void
    {
        $this->writeLine(sprintf(
            '[%s] %s %s: %s%s',
            self::timestamp(),
            $level->icon(),
            $level->value,
            $message,
            PHP_EOL,
        ));
    }

    public function logPacket(
        string $direction,
        int $clientId,
        int $packetId,
        string $payload,
    ): void {
        // Formatting done in one pass over the payload (see HexFormatter).
        $this->writeLine(\Hub\Log\HexFormatter::packet(
            self::timestamp(),
            $direction,
            $clientId,
            $packetId,
            $payload,
        ));
    }

    /**
     * Explicitly releases the file handle (used by shutdown routines so we do
     * not rely on destructor ordering).
     */
    public function close(): void
    {
        if (is_resource($this->handle)) {
            fflush($this->handle);
            fclose($this->handle);
        }

        $this->handle = null;
    }

    private function writeLine(string $line): void
    {
        $handle = $this->handle();

        if (fwrite($handle, $line) === false) {
            // Never let logging take down the server loop.
            $this->handle = null;
        }
    }

    /** @return resource */
    private function handle()
    {
        if ($this->handle === null) {
            $mode = $this->truncateOnOpen ? 'w' : 'a';
            $handle = @fopen($this->path, $mode);

            if ($handle === false) {
                throw new \RuntimeException(sprintf('Cannot open log file "%s".', $this->path));
            }

            $this->truncateOnOpen = false; // truncate only once
            $this->handle = $handle;
        }

        return $this->handle;
    }

    private static function timestamp(): string
    {
        // microseconds without spawning DateTime objects per call
        $now = microtime(true);
        $us  = (int) fmod($now * 1e6, 1e6);

        return sprintf('%s.%06d', date('Y-m-d H:i:s', (int) $now), $us);
    }
}
