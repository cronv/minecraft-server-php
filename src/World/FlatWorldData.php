<?php

declare(strict_types=1);

namespace Hub\World;

use Hub\Protocol\BinaryStream;
use Hub\Nbt\RegistryTagsBuilder;

/**
 * Flyweight + Lazy Loading world data provider.
 *
 * The legacy WorldGenerator re-serialised the identical flat chunk (~700 B)
 * and tags blob for EVERY player, byte by byte, through string concatenation
 * (O(n²) `.=` growth). Chunk bytes are immutable shared state: we build each
 * blob exactly once on first use (lazy) and hand out the same interned
 * string to all clients — N logins ⇒ 1 generation.
 */
final class FlatWorldData
{
    private ?string $chunkPacketBody = null;

    private ?string $registryTagsBody = null;

    public function __construct(private readonly FlatWorldPreset $preset)
    {
    }

    /**
     * Body of packet 0x22 (Chunk Data), cached after first call.
     */
    public function chunkData(): string
    {
        return $this->chunkPacketBody ??= $this->buildChunkData();
    }

    /**
     * Body of packet 0x5A (Added/Removed Game Event / registry data stub).
     */
    public function registryTags(): string
    {
        return $this->registryTagsBody ??= (new RegistryTagsBuilder())->build();
    }

    private function buildChunkData(): string
    {
        $out = new BinaryStream(spillThreshold: 64 * 1024);

        // Chunk coordinates (big-endian ints)
        $out->writeInt32(0); // X
        $out->writeInt32(0); // Z

        // Full chunk flag (VarInt in 1.20.1; value 1 encodes to one byte)
        $out->writeVarInt(1);

        // Heightmaps (NBT-ish JSON stub kept byte-compatible with the legacy server)
        $heightmaps = json_encode(
            ['MOTION_BLOCKING' => ['0' => []], 'WORLD_SURFACE' => ['0' => []]],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        $out->writeString((string) $heightmaps);

        // Biomes: single-value palette (bits_per_entry = 0, empty data array)
        // is protocol-valid and replaces the legacy 1024-iteration VarInt loop.
        $out->writeVarInt(1);              // palette size
        $out->writeVarInt(1);              // minecraft:plains
        $out->writeVarInt(0);              // bits per entry
        $out->writeVarInt(0);              // data array length

        // Sections
        $out->writeVarInt(1);              // one section
        $out->writeVarInt(0);              // section Y
        $out->writeVarInt(2);              // block palette: air + stone
        $out->writeString('minecraft:air');
        $out->writeString('minecraft:stone');
        $out->writeVarInt(1);              // bits per block
        $out->writeVarInt(1024);           // long array length (matches legacy)
        $out->writeZeros(512);             // all-zero indices → air everywhere
        $out->writeVarInt(0);              // block light: all zero (varlen)
        $out->writeVarInt(0);              // sky light: all zero (varlen)

        // Block entities
        $out->writeVarInt(0);

        return $out->contents();
    }
}
