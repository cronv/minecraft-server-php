<?php

declare(strict_types=1);

namespace Hub\Nbt;

use Hub\Config\ServerConfig;
use Hub\Protocol\BinaryStream;

/**
 * Template Method: fixed skeleton (root compound → named registry entries →
 * per-entry write hook → terminators), variable step delegated to subclasses.
 *
 * The legacy code hand-rolled two nearly identical 60-line blocks (dimension
 * registry inside PacketFactory and "simple tags" inside NBTWriter) with copy
 * -paste loops and magic integers. Both now share this skeleton.
 */
abstract class RegistryNbtBuilder
{
    final public function build(): string
    {
        $stream = new BinaryStream(spillThreshold: 256 * 1024);
        $nbt    = new NbtStream($stream);

        $nbt->startRoot();

        foreach ($this->registries() as $registryId => $entries) {
            $nbt->startCompound($registryId);
            $nbt->startList('value', NbtStream::TAG_COMPOUND, count($entries));

            foreach ($entries as $entryName => $entry) {
                $nbt->openUnnamedCompound();
                $nbt->string('name', (string) $entryName);
                $nbt->int('id', (int) ($entry['id'] ?? 0));
                $nbt->startCompound('element');
                $this->writeElement($nbt, $entry);
                $nbt->endCompound();
                $nbt->closeUnnamedCompound();
            }

            // close the outer registry compound
            $nbt->endCompound();
        }

        $nbt->endCompound(); // root

        return $stream->contents();
    }

    /**
     * @return array<string, array<string, array<string, mixed>>> registry id ⇒ entries
     */
    abstract protected function registries(): array;

    /**
     * Hook: writes the inner `element` compound fields for one entry.
     *
     * @param array<string, mixed> $entry
     */
    abstract protected function writeElement(NbtStream $nbt, array $entry): void;
}
