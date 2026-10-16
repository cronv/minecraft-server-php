<?php

declare(strict_types=1);

namespace Hub\Config;

use Hub\World\FlatWorldPreset;

/**
 * Immutable-by-default server configuration.
 *
 * Replaces the untyped `get(string $key, $default = null)` bag of the legacy
 * ServerConfig: every option is now a promoted readonly property with a real
 * type (LSP-safe, IDE/static-analysis friendly, zero typos at call sites).
 */
final class ServerConfig
{
    public function __construct(
        public readonly string $host = '0.0.0.0',
        public readonly int $port = 25565,
        public readonly int $protocolVersion = 763, // Minecraft 1.20.1
        public readonly string $serverName = 'PHP Minecraft Hub Server',
        public readonly string $motd = "§a✓ PHP Hub Server §7| §fMinecraft 1.20.1\n§eДобро пожаловать!",
        public readonly int $maxPlayers = 20,
        public readonly int $viewDistance = 10,
        public readonly int $simulationDistance = 10,
        public readonly int $keepAliveIntervalSeconds = 15,
        public readonly int $connectionTimeoutSeconds = 30,
        public readonly FlatWorldPreset $world = new FlatWorldPreset(),
    ) {
    }

    /**
     * Pre-serialized dimension-type registry NBT (Flyweight: built once per
     * process, shared by every Join Game packet instead of re-generating a
     * ~300-byte tree on each login).
     */
    public function dimensionRegistryNbt(): string
    {
        static $nbt = null;

        return $nbt ??= (new \Hub\Nbt\DimensionRegistryBuilder())->build();
    }

    /**
     * PSR-12-friendly factory used by the entry script; env overrides keep it
     * deployable without code edits.
     */
    public static function fromEnvironment(): self
    {
        $config = new self();

        $port = getenv('HUB_PORT');

        if ($port !== false && ctype_digit($port)) {
            return new self(
                host: (getenv('HUB_HOST') ?: $config->host),
                port: (int) $port,
            );
        }

        return $config;
    }
}
