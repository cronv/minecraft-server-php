<?php

declare(strict_types=1);

namespace Hub\Protocol;

/**
 * Stateless VarInt codec (Minecraft protocol, 763 / 1.20.1).
 *
 * Replaces the legacy `VarInt` class. Key improvements:
 *  - single-byte fast path cached via a static table (Flyweight): writing the
 *    extremely common values 0..127 allocates nothing after first use;
 *  - explicit overflow guard (max 5 bytes / shift 28) instead of the buggy
 *    `$shift > 35` check of the original;
 *  - operates on {@see PacketBuffer} so callers stop re-scanning strings.
 */
final class VarIntCodec
{
    /** Hard protocol limit: a VarInt is at most 5 bytes. */
    private const MAX_BYTES = 5;

    /** @var array<int, string> flyweight cache of one-byte encodings */
    private static array $singleByteCache = [];

    /**
     * Reads a VarInt from $buffer starting at $offset.
     *
     * @param int $offset advanced past the consumed bytes on success
     *
     * @return int|null null when the buffer does not (yet) contain a full VarInt
     *
     * @throws \RuntimeException on malformed (overlong) VarInts
     */
    public static function read(string $buffer, int &$offset): ?int
    {
        $value = 0;
        $shift = 0;
        $length = strlen($buffer);

        while (true) {
            if ($offset >= $length) {
                return null; // incomplete — wait for more data
            }

            $byte = ord($buffer[$offset++]);
            $value |= ($byte & 0x7F) << $shift;

            if (($byte & 0x80) === 0) {
                return $value;
            }

            $shift += 7;

            if ($shift >= self::MAX_BYTES * 7) {
                throw new \RuntimeException('VarInt is too large (> 5 bytes).');
            }
        }
    }

    /**
     * Encodes an int as a VarInt byte string.
     */
    public static function write(int $value): string
    {
        // Fast path: 0..127 → one byte, served from the flyweight table.
        if ($value >= 0 && $value < 0x80) {
            return self::$singleByteCache[$value] ??= chr($value);
        }

        $bytes = '';

        // Protocol VarInts carry unsigned LEB128 semantics; mask the value to
        // 32 bits and use a logical (unsigned) shift to avoid sign issues.
        $remaining = $value & 0xFFFFFFFF;

        do {
            $temp = $remaining & 0x7F;
            $remaining >>= 7; // $remaining is guaranteed non-negative after masking

            if ($remaining !== 0) {
                $temp |= 0x80;
            }

            $bytes .= chr($temp);
        } while ($remaining !== 0);

        return $bytes;
    }
}
