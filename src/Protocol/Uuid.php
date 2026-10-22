<?php

declare(strict_types=1);

namespace Hub\Protocol;

/**
 * Deterministic offline-UUID helper (replaces UUIDGenerator).
 *
 * The legacy generator mixed mt_rand() with crc32(username . time()) and used
 * `time()` seconds resolution — two logins in the same second could collide.
 * This version uses random_bytes() (CSPRNG) and formats a proper v4 UUID.
 */
final class Uuid
{
    /**
     * RFC 4122 v4 UUID in canonical dashed text form.
     */
    public static function v4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40); // version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80); // variant 10xx

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * Converts canonical dashed UUID text into 16 raw bytes.
     */
    public static function toBinary(string $uuid): string
    {
        $binary = hex2bin(str_replace('-', '', $uuid));

        if ($binary === false || strlen($binary) !== 16) {
            throw new \InvalidArgumentException(sprintf('Malformed UUID: "%s".', $uuid));
        }

        return $binary;
    }
}
