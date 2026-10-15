<?php

declare(strict_types=1);

namespace Hub\Log;

/**
 * Static helpers turning raw packet bytes into a readable hex dump.
 *
 * The legacy formatHex() built its result with `.=` inside a loop AND called
 * str_split() producing arrays of thousands of tiny strings per packet —
 * measurable GC pressure on the hot path. Here we use chunk_split()/rtrim,
 * which run in C and allocate exactly two intermediates.
 */
final class HexFormatter
{
    private const BYTES_PER_LINE = 16; // 32 hex chars

    private function __construct()
    {
    }

    public static function hexDump(string $payload): string
    {
        if ($payload === '') {
            return '';
        }

        $hex = bin2hex($payload);

        // All separator insertion happens in C:
        //   "a1b2" -> "a1 b2\n" — exactly two intermediate strings, no arrays.
        $spaced = chunk_split($hex, 2, ' ');           // byte pairs + space
        $lines  = chunk_split($spaced, self::BYTES_PER_LINE * 3, "\n"); // 16 bytes/line

        // Trim trailing separators of the final line, then indent every line.
        $trimmed = rtrim($lines, " \n");
        $indented = preg_replace('/^/m', '  ', $trimmed);

        return $indented ?? '';
    }

    public static function packet(
        string $timestamp,
        string $direction,
        int $clientId,
        int $packetId,
        string $payload,
    ): string {
        return sprintf(
            "[%s] %s | Client %d | Packet 0x%02X (%s) | Length: %d bytes\n%s%s",
            $timestamp,
            $direction,
            $clientId,
            $packetId,
            \Hub\Protocol\PacketId::nameFor($packetId),
            strlen($payload),
            self::hexDump($payload),
            PHP_EOL,
        );
    }
}
