<?php

declare(strict_types=1);

namespace Hub\Packet;

use Hub\Config\ServerConfig;
use Hub\Connection\ClientConnection;
use Hub\Protocol\BinaryStream;
use Hub\Protocol\Uuid;

/**
 * Static factory methods for the remaining small packets (Factory pattern).
 * Each returns only the packet BODY; framing belongs to ConnectionManager.
 */
final class PacketFactory
{
    private function __construct()
    {
    }

    /**
     * 0x37 Synchronize Player Position.
     */
    public static function synchronizePlayerPosition(ServerConfig $config): string
    {
        $world = $config->world;
        $out   = new BinaryStream();

        $out->writeFloat64($world->spawnX);
        $out->writeFloat64($world->spawnY);
        $out->writeFloat64($world->spawnZ);
        $out->writeFloat32(0.0);            // yaw
        $out->writeFloat32(0.0);            // pitch
        $out->writeU8(0x00);                // flags: absolute
        $out->writeVarInt(1);               // teleport id

        return $out->contents();
    }

    /**
     * 0x39 Player Abilities.
     */
    public static function playerAbilities(): string
    {
        $out = new BinaryStream();
        $out->writeU8(0x09);        // flags: allow flying + flying disabled set
        $out->writeFloat32(0.05);   // fly speed
        $out->writeFloat32(0.1);    // walk speed

        return $out->contents();
    }

    /**
     * 0x1F Keep Alive (server-bound payload: i64 id).
     */
    public static function keepAlive(int $id): string
    {
        $out = new BinaryStream();
        $out->writeInt64($id);

        return $out->contents();
    }

    /**
     * 0x41 Set Spawn Position (position encoded as protocol BlockPos u64).
     */
    public static function spawnPosition(int $x = 0, int $y = 64, int $z = 0): string
    {
        $out = new BinaryStream();

        // BlockPos: (x & 0x3FFFFFF) << 38 | (y & 0xFFF) << 26 | (z & 0x3FFFFFF)
        $pos = (($x & 0x3FFFFFF) << 38) | (($y & 0xFFF) << 26) | ($z & 0x3FFFFFF);
        $out->writeInt64($pos);
        $out->writeFloat32(0.0); // angle

        return $out->contents();
    }

    /**
     * 0x08 Feature Flags — empty list.
     */
    public static function featureFlags(): string
    {
        return "\x00"; // VarInt 0
    }

    /**
     * 0x32 Player Info (Add Player action) for one player.
     */
    public static function playerInfoAdd(string $username, string $uuid): string
    {
        $out = new BinaryStream();

        $out->writeVarInt(0);                    // action: add player
        $out->writeVarInt(1);                    // players: 1
        $out->writeRaw(Uuid::toBinary($uuid));   // uuid
        $out->writeString($username);
        $out->writeVarInt(0);                    // properties
        $out->writeVarInt(0);                    // gamemode survival
        $out->writeVarInt(0);                    // ping
        $out->writeU8(0);                        // no display name

        return $out->contents();
    }

    /**
     * Status Response body: JSON chat component framed as protocol String.
     *
     * @param \Closure(array):string $jsonEncoder injected to keep this class
     *        free of runtime dependencies on live counters (Strategy-lite for
     *        testability)
     */
    public static function statusResponse(
        ServerConfig $config,
        int $onlinePlayers,
        \Closure $jsonEncoder,
    ): string {
        $payload = [
            'version' => [
                'name'     => '1.20.1',
                'protocol' => $config->protocolVersion,
            ],
            'players' => [
                'max'    => $config->maxPlayers,
                'online' => $onlinePlayers,
                'sample' => [],
            ],
            'description' => ['text' => $config->motd],
            'favicon' => '',
            'enforcesSecureChat' => false,
        ];

        $json = $jsonEncoder($payload);
        $out  = new BinaryStream();
        $out->writeString($json);

        return $out->contents();
    }
}
