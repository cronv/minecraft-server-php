<?php

declare(strict_types=1);

namespace Hub\Nbt;

/**
 * Concrete Template: the dimension-type registry sent inside Join Game.
 * Data lives in one declarative array — no 120-line method of magic numbers.
 */
final class DimensionRegistryBuilder extends RegistryNbtBuilder
{
    protected function registries(): array
    {
        return [
            'minecraft:dimension_type' => [
                'minecraft:overworld' => [
                    'id' => 0,
                    'fields' => self::overworldElement(),
                ],
            ],
        ];
    }

    protected function writeElement(NbtStream $nbt, array $entry): void
    {
        /** @var array<string, array{type: string, value: int|float|string}> $fields */
        $fields = $entry['fields'] ?? [];

        foreach ($fields as $name => $field) {
            match ($field['type']) {
                'byte'   => $nbt->byte($name, (int) $field['value']),
                'int'    => $nbt->int($name, (int) $field['value']),
                'double' => $nbt->double($name, (float) $field['value']),
                'string' => $nbt->string($name, (string) $field['value']),
                default  => throw new \InvalidArgumentException(
                    sprintf('Unsupported NBT field type "%s".', $field['type'])
                ),
            };
        }
    }

    /**
     * Vanilla overworld dimension-type fields (1.20.1).
     *
     * @return array<string, array{type: string, value: int|float|string}>
     */
    private static function overworldElement(): array
    {
        return [
            'ultrawarm'                        => ['type' => 'byte',   'value' => 0],
            'natural'                          => ['type' => 'byte',   'value' => 1],
            'coordinate_scale'                 => ['type' => 'double', 'value' => 1.0],
            'ambient_light'                    => ['type' => 'double', 'value' => 0.0],
            'has_ceiling'                      => ['type' => 'byte',   'value' => 0],
            'has_skylight'                     => ['type' => 'byte',   'value' => 1],
            'piglin_safe'                      => ['type' => 'byte',   'value' => 0],
            'bed_works'                        => ['type' => 'byte',   'value' => 1],
            'respawn_anchor_works'             => ['type' => 'byte',   'value' => 0],
            'has_raids'                        => ['type' => 'byte',   'value' => 1],
            'min_y'                            => ['type' => 'int',    'value' => 0],
            'height'                           => ['type' => 'int',    'value' => 256],
            'logical_height'                   => ['type' => 'int',    'value' => 256],
            'infiniburn'                       => ['type' => 'string', 'value' => '#minecraft:infiniburn_overworld'],
            'effects'                          => ['type' => 'string', 'value' => 'minecraft:overworld'],
            'monster_spawn_light_level'        => ['type' => 'int',    'value' => 7],
            'monster_spawn_block_light_limit'  => ['type' => 'int',    'value' => 0],
        ];
    }
}
