<?php

declare(strict_types=1);

namespace Hub\Packet;

use Hub\Config\ServerConfig;
use Hub\Protocol\BinaryStream;

/**
 * Builder for every server-bound packet the hub emits.
 *
 * Replaces the legacy god-class PacketFactory which (a) held a back-reference
 * to MinecraftServer (object cycle Server→Factory→Server), (b) rebuilt the
 * identical dimension-registry NBT on every login, and (c) concatenated the
 * whole packet with `.=` byte by byte.
 *
 * Now: pure function of (config, inputs) → bytes. No cycles, fully unit
 * testable without sockets.
 */
final class JoinGameBuilder
{
    public function __construct(private readonly ServerConfig $config)
    {
    }

    /**
     * Body of packet 0x29 Join Game (Login) — protocol 763 / 1.20.1.
     * (ВНИМАНИЕ: 0x27 в Protocol 763 — это Set Container Content, а НЕ Join Game!)
     */
    public function build(string $dimensionName = 'minecraft:overworld'): string
    {
        $world = $this->config->world;
        $out   = new BinaryStream(spillThreshold: 64 * 1024);

        $out->writeInt32(1);              // Entity ID (player)
        $out->writeU8(0);                 // Hardcore: false
        $out->writeU8(0);                 // Gamemode: Survival
        $out->writeU8(255);               // Previous gamemode: none
        $out->writeVarInt(1);             // World count
        $out->writeString($world->name);  // Dimension names

        // Dimension codec registry (NBT) — Flyweight-cached in config.
        $out->writeRaw($this->config->dimensionRegistryNbt());

        $out->writeString('minecraft:dimension_type'); // dimension type key
        $out->writeString($dimensionName);             // dimension
        $out->writeString($world->name);               // world name
        $out->writeInt64($world->seed);                // hashed seed
        $out->writeVarInt($this->config->maxPlayers);
        $out->writeVarInt($this->config->viewDistance);
        $out->writeVarInt($this->config->simulationDistance);
        $out->writeI8(0);                   // Reduced debug info: false
        $out->writeI8(1);                   // Enable respawn screen: true
        // NOTE: `is_debug` and `is_flat` were REMOVED in 1.20 — do not send.

        return $out->contents();
    }
}
