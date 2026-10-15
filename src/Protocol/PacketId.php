<?php

declare(strict_types=1);

namespace Hub\Protocol;

/**
 * Known packet identifiers with human-readable names (flyweight replacement
 * for the per-call `$names` array in the legacy PacketLogger).
 */
enum PacketId: int
{
    // Server-bound / client-bound shared low ids are disambiguated by state.
    case StatusResponse      = 0x00;
    case PingResponse        = 0x01;
    case LoginSuccess        = 0x02;
    case FeatureFlags        = 0x08;
    case KeepAlive           = 0x1F;
    case ChunkData           = 0x22;
    case JoinGame            = 0x26;
    case PlayerInfo          = 0x32;
    case PlayerPositionLook  = 0x37;
    case PlayerAbilities     = 0x39;
    case SpawnPosition       = 0x41;
    case Tags                = 0x5A;

    /**
     * Lazily-built static table (constructed once, shared forever — zero
     * per-call allocations, unlike the original code).
     */
    public static function nameFor(int $packetId): string
    {
        /** @var array<int, string>|null $map */
        static $map = null;

        if ($map === null) {
            $map = [];
            foreach (self::cases() as $case) {
                $map[$case->value] = $case->name;
            }
        }

        return $map[$packetId] ?? 'Unknown';
    }
}
