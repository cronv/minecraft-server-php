<?php

declare(strict_types=1);

namespace Hub\Protocol;

/**
 * Append-only binary writer built on an open PHP stream (php://memory →
 * php://temp spill after 2 MB).
 *
 * WHY: the legacy NBTWriter/WorldGenerator concatenated strings with `.=`
 * inside tight loops. Each `.=` on a multi-hundred-KB string can trigger a
 * reallocation + full memcpy, and the 1024-iteration VarInt loop produced
 * thousands of intermediate strings. Streaming into a buffer keeps memory
 * growth amortised and lets large payloads (chunk data) be written out with
 * stream_copy_to_stream() without ever holding the whole body twice in RAM.
 */
final class BinaryStream
{
    /** @var resource */
    private readonly $handle;

    private int $length = 0;

    public function __construct(private readonly int $spillThreshold = 2 * 1024 * 1024)
    {
        $mode = $this->spillThreshold > 0 ? 'w+' : 'w+';
        $handle = $this->spillThreshold > 0
            ? fopen('php://temp:max_memory=' . $this->spillThreshold, $mode)
            : fopen('php://memory', $mode);

        if ($handle === false) {
            throw new \RuntimeException('Unable to open binary buffer stream.');
        }

        $this->handle = $handle;
    }

    public function __destruct()
    {
        // Deterministic release of the underlying resource — no fd leaks.
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }

    public function length(): int
    {
        return $this->length;
    }

    public function writeByte(int $value): self
    {
        return $this->raw(chr($value & 0xFF));
    }

    public function writeI8(int $value): self
    {
        return $this->raw(pack('c', $value));
    }

    public function writeU8(int $value): self
    {
        return $this->raw(pack('C', $value));
    }

    public function writeInt32(int $value): self
    {
        return $this->raw(pack('N', $value));
    }

    public function writeInt64(int $value): self
    {
        return $this->raw(pack('J', $value));
    }

    public function writeFloat32(float $value): self
    {
        return $this->raw(pack('G', $value));
    }

    /**
     * NBT doubles are big-endian; pack('E') gives exactly that.
     */
    public function writeFloat64(float $value): self
    {
        return $this->raw(pack('E', $value));
    }

    public function writeVarInt(int $value): self
    {
        return $this->raw(VarIntCodec::write($value));
    }

    /**
     * Protocol string: VarInt length prefix + raw UTF-8 bytes.
     */
    public function writeString(string $value): self
    {
        $this->writeVarInt(strlen($value));

        return $this->raw($value);
    }

    /**
     * NBT string: unsigned 16-bit big-endian length prefix.
     */
    public function writeNbtString(string $value): self
    {
        return $this->raw(pack('n', strlen($value)) . $value);
    }

    public function writeRaw(string $bytes): self
    {
        return $this->raw($bytes);
    }

    public function writeZeros(int $count): self
    {
        // Write in bounded chunks so we materialise at most 64 KiB at a time.
        $chunk = str_repeat("\x00", min($count, 65536));

        while ($count > 0) {
            $take = min($count, strlen($chunk));
            $this->raw(substr($chunk, 0, $take));
            $count -= $take;
        }

        return $this;
    }

    private function raw(string $bytes): self
    {
        if ($bytes === '') {
            return $this;
        }

        $written = fwrite($this->handle, $bytes);

        if ($written === false || $written !== strlen($bytes)) {
            throw new \RuntimeException('Binary buffer write failed.');
        }

        $this->length += $written;

        return $this;
    }

    /**
     * Returns the whole payload as a string (single copy). Prefer
     * {@see copyTo()} for large payloads when the consumer is a stream.
     */
    public function contents(): string
    {
        rewind($this->handle);

        return stream_get_contents($this->handle) ?: '';
    }

    /**
     * Streams the payload straight into another resource (sendfile-style),
     * never building the full packet string in PHP userland memory.
     */
    public function copyTo($destinationStream): int|false
    {
        rewind($this->handle);

        return stream_copy_to_stream($this->handle, $destinationStream);
    }

    /**
     * Rewinds so the buffer can be consumed/reused.
     */
    public function clear(): void
    {
        rewind($this->handle);
        ftruncate($this->handle, 0);
        $this->length = 0;
    }
}
