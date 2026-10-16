<?php

declare(strict_types=1);

namespace Hub\World;

/**
 * Value object describing the flat hub world (was an untyped array).
 */
final class FlatWorldPreset
{
    public function __construct(
        public readonly string $name = 'minecraft:overworld',
        public readonly int $seed = 0,
        public readonly float $spawnX = 0.5,
        public readonly float $spawnY = 64.0,
        public readonly float $spawnZ = 0.5,
    ) {
    }

    public static function default(): self
    {
        return new self(seed: crc32('default_seed'));
    }
}
