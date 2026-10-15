<?php

declare(strict_types=1);

namespace Hub\Nbt;

use Hub\Protocol\BinaryStream;

/**
 * Fluent NBT tag writer built on {@see BinaryStream} (Builder pattern).
 *
 * Fixes vs the legacy NBTWriter:
 *  - proper big-endian doubles via pack('E') instead of touching a private
 *    member from outside (`$writer->buffer .= pack('d', …)` broke both
 *    encapsulation AND endianness);
 *  - typed methods for every tag actually used → no magic numbers at call
 *    sites;
 *  - list headers take element type + count explicitly.
 */
final class NbtStream
{
    public const TAG_END       = 0;
    public const TAG_BYTE      = 1;
    public const TAG_SHORT     = 2;
    public const TAG_INT       = 3;
    public const TAG_LONG      = 4;
    public const TAG_FLOAT     = 5;
    public const TAG_DOUBLE    = 6;
    public const TAG_BYTE_ARR  = 7;
    public const TAG_STRING    = 8;
    public const TAG_LIST      = 9;
    public const TAG_COMPOUND  = 10;

    public function __construct(private readonly BinaryStream $out)
    {
    }

    public function startRoot(): self
    {
        return $this->startCompound('');
    }

    public function startCompound(string $name): self
    {
        return $this->tag(self::TAG_COMPOUND, $name)->open();
    }

    public function endCompound(): self
    {
        $this->out->writeByte(self::TAG_END);

        return $this;
    }

    /** Opens a list of homogeneous payload tags: header only. */
    public function startList(string $name, int $elementType, int $count): self
    {
        $this->tag(self::TAG_LIST, $name);
        $this->out->writeByte($elementType);
        $this->out->writeInt32($count);

        return $this;
    }

    public function string(string $name, string $value): self
    {
        $this->tag(self::TAG_STRING, $name);
        $this->out->writeNbtString($value);

        return $this;
    }

    public function byte(string $name, int $value): self
    {
        $this->tag(self::TAG_BYTE, $name);
        $this->out->writeI8($value);

        return $this;
    }

    public function int(string $name, int $value): self
    {
        $this->tag(self::TAG_INT, $name);
        $this->out->writeInt32($value);

        return $this;
    }

    public function double(string $name, float $value): self
    {
        $this->tag(self::TAG_DOUBLE, $name);
        $this->out->writeFloat64($value); // big-endian — correct NBT

        return $this;
    }

    /** Payload-only compound opener (inside lists, where names are absent). */
    public function openUnnamedCompound(): self
    {
        $this->out->writeByte(self::TAG_COMPOUND);

        return $this;
    }

    public function closeUnnamedCompound(): self
    {
        $this->out->writeByte(self::TAG_END);

        return $this;
    }

    public function stream(): BinaryStream
    {
        return $this->out;
    }

    // ---- internals -------------------------------------------------------

    private function tag(int $type, string $name): self
    {
        $this->out->writeByte($type);
        $this->out->writeNbtString($name);

        return $this;
    }

    private function open(): self
    {
        // Compounds carry no extra header bytes; kept for readability.
        return $this;
    }
}
