<?php

declare(strict_types=1);

namespace Hub\Nbt;

/**
 * Concrete Template: registry data packet (0x5A "Tags") — seven registries,
 * each with an empty element list. The legacy version repeated the same
 * 6-line magic-number block 7 times (~50 lines of copy-paste).
 */
final class RegistryTagsBuilder extends RegistryNbtBuilder
{
    private const REGISTRY_IDS = [
        'minecraft:dimension_type'      => ['minecraft:overworld', []],
        'minecraft:worldgen/biome'      => ['minecraft:plains', []],
        'minecraft:chat_type'           => [],
        'minecraft:trim_material'       => [],
        'minecraft:trim_pattern'        => [],
        'minecraft:wolf_variant'        => [],
        'minecraft:painting_variant'    => [],
    ];

    protected function registries(): array
    {
        return self::REGISTRY_IDS;
    }

    protected function writeElement(NbtStream $nbt, array $entry): void
    {
        // Empty element payload by design: hub clients tolerate stubs.
    }
}
