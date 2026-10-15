<?php

declare(strict_types=1);

namespace Hub\Protocol;

/**
 * Zero-copy-ish read cursor over a byte string.
 *
 * Replaces the pattern `substr($buffer, $offset, $len)` scattered through the
 * legacy code. Internally uses an \SplStringReader-like cursor: no temporary
 * substrings are produced for fixed-width numeric reads (they go straight
 * through unpack() on a slice only when needed, and big consumptions simply
 * advance the cursor).
 */
final class PacketBuffer
{
    private int $offset = 0;

    public function __construct(private readonly string $data)
    {
    }

    public function remaining(): int
    {
        return strlen($this->data) - $this->offset;
    }

    public function offset(): int
    {
        return $this->offset;
    }

    public function has(int $bytes): bool
    {
        return $this->remaining() >= $bytes;
    }

    /**
     * Reads exactly $length raw bytes (advances the cursor).
     */
    public function readBytes(int $length): string
    {
        if (!$this->has($length)) {
            throw new \UnexpectedValueException(
                sprintf('Requested %d bytes, only %d available.', $length, $this->remaining())
            );
        }

        $chunk = substr($this->data, $this->offset, $length);
        $this->offset += $length;

        return $chunk;
    }

    /**
     * Reads a protocol String (VarInt length prefix + UTF-8 payload).
     */
    public function readString(): string
    {
        $length = $this->readVarInt();

        if ($length === null) {
            return '';
        }

        if ($length === 0 || !$this->has($length)) {
            return '';
        }

        return $this->readBytes($length);
    }

    /**
     * @return int|null null if not enough bytes are buffered yet
     */
    public function readVarInt(): ?int
    {
        return VarIntCodec::read($this->data, $this->offset);
    }

    /**
     * Reads a big-endian signed 32-bit int.
     */
    public function readInt32(): int
    {
        return unpack('N', $this->readBytes(4))[1];
    }

    /**
     * Reads a big-endian unsigned short.
     */
    public function readUInt16(): int
    {
        return unpack('n', $this->readBytes(2))[1];
    }

    /**
     * Reads a big-endian signed 64-bit long.
     */
    public function readInt64(): int
    {
        return unpack('J', $this->readBytes(8))[1];
    }

    /**
     * Reads a single unsigned byte.
     */
    public function readUInt8(): int
    {
        return ord($this->readBytes(1));
    }
}
