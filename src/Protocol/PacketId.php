<?php

declare(strict_types=1);

namespace Hub\Protocol;

/**
 * Known packet identifiers with human-readable names (flyweight replacement
 * for the per-call `$names` array in the legacy PacketLogger).
 *
 * Protocol 763 (Minecraft 1.20.1) — точные значения ID пакетов.
 *
 * NOTE: PHP enums cannot declare constants, so the full Play-state clientbound
 * table lives in the companion class {@see PacketIdPlay} below; the enum keeps
 * state-disambiguated cases used by loggers (nameFor()).
 */
enum PacketId: int
{
    // Server-bound / client-bound shared low ids are disambiguated by state.

    // --- Login State (Server → Client), Protocol 763 ---
    case CB_LOGIN_DISCONNECT = 0x00;
    case CB_LOGIN_ENCRYPTION_REQUEST = 0x01;
    case LoginSuccess        = 0x02;
    case CB_LOGIN_SET_COMPRESSION = 0x03;
    case CB_LOGIN_PLUGIN_REQUEST = 0x04;

    // --- Login State (Client → Server), Protocol 763 ---
    case SB_LOGIN_START = 0x00;
    case SB_LOGIN_ENCRYPTION_RESPONSE = 0x01;
    case SB_LOGIN_PLUGIN_RESPONSE = 0x02;
    case SB_LOGIN_LOGIN_ACKNOWLEDGED = 0x03;

    // Старые имена (совместимость с legacy-кодом логгера):
    case StatusResponse      = 0x00;
    case PingResponse        = 0x01;
    case FeatureFlags        = 0x08;
    case KeepAlive           = 0x1F;
    case ChunkData           = 0x22;
    case JoinGame            = 0x29; // Исправлено: БЫЛО 0x26/0x27, СТАЛО 0x29
    case PlayerInfo          = 0x32;
    case PlayerPositionLook  = 0x3A; // Исправлено: БЫЛО 0x37, СТАЛО 0x3A
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

/**
 * Play State — Clientbound (Server → Client), Protocol 763 (1.20.1).
 *
 * Ключевые исправления против старой таблицы:
 *  - CB_PLAY_LOGIN = 0x29 (Join Game). Ранее ошибочно 0x27 — это
 *    Set Container Content; отправка NBT-структуры мира как обновления
 *    инвентаря ломала парсер клиента (таймаут на «Подключение к серверу…»).
 *  - CB_PLAY_SET_CONTAINER_CONTENT = 0x27 — вынесено отдельной константой.
 *  - PLAY_MAP_CHUNK (Level Chunk With Light) остаётся 0x23.
 */
final class PacketIdPlay
{
    public const CB_PLAY_BUNDLE_DELIMITER = 0x00;
    public const CB_PLAY_ADD_ENTITY = 0x01;                  // Spawn Entity
    public const CB_PLAY_ADD_EXPERIENCE_ORB = 0x02;
    public const CB_PLAY_ADD_PLAYER = 0x03;
    public const CB_PLAY_ANIMATE = 0x04;
    public const CB_PLAY_AWARD_STATISTICS = 0x05;
    public const CB_PLAY_BLOCK_CHANGED_ACK = 0x06;           // Update Block Entity NBT
    public const CB_PLAY_BLOCK_DESTRUCTION = 0x07;
    public const CB_PLAY_BLOCK_ENTITY_DATA = 0x08;
    public const CB_PLAY_BLOCK_EVENT = 0x09;
    public const CB_PLAY_BLOCK_UPDATE = 0x0A;
    public const CB_PLAY_BOSS_EVENT = 0x0B;
    public const CB_PLAY_CHANGE_DIFFICULTY = 0x0C;           // Server Difficulty
    public const CB_PLAY_CHUNK_BATCH_FINISHED = 0x0D;        // Empty Chunk Snapshot
    public const CB_PLAY_CHUNK_BATCH_START = 0x0E;
    public const CB_PLAY_CHUNK_BIOMES = 0x0F;
    public const CB_PLAY_CLEAR_TITLES = 0x10;
    public const CB_PLAY_COMMAND_SUGGESTIONS = 0x11;
    public const CB_PLAY_DECLARE_COMMANDS = 0x12;            // Commands (Declare Commands)
    public const CB_PLAY_CONTAINER_CLOSE = 0x13;
    public const CB_PLAY_CONTAINER_SET_SLOT = 0x16;
    public const CB_PLAY_COOLDOWN = 0x17;
    public const CB_PLAY_CUSTOM_PAYLOAD = 0x19;              // Custom Payload / Plugin Message
    public const CB_PLAY_DAMAGE_EVENT = 0x1A;                // Hurt Animation
    public const CB_PLAY_DELETE_CHAT = 0x1B;
    public const CB_PLAY_DISCONNECT = 0x1C;
    public const CB_PLAY_DISGUISED_CHAT = 0x1D;
    public const CB_PLAY_ENTITY_EVENT = 0x1E;
    public const CB_PLAY_EXPLOSION = 0x1F;
    public const CB_PLAY_FORGET_LEVEL_CHUNK = 0x20;          // Unload Chunk
    public const CB_PLAY_GAME_EVENT = 0x21;                  // World Events (Game Event)
    public const CB_PLAY_KEEP_ALIVE = 0x22;                  // Keep Alive
    public const CB_PLAY_LEVEL_CHUNK_WITH_LIGHT = 0x23;      // Map Chunk (Chunk Data)
    public const CB_PLAY_LEVEL_EVENT = 0x24;
    public const CB_PLAY_LEVEL_PARTICLES = 0x25;
    public const CB_PLAY_LIGHT_UPDATE = 0x26;
    public const CB_PLAY_SET_CONTAINER_CONTENT = 0x27;       // Set Container Content (НЕ Join Game!)
    public const CB_PLAY_MAP_ITEM_DATA = 0x28;
    /** Protocol 763: Login (Join Game) — ИМЕННО 0x29 (было ошибочно 0x27). */
    public const CB_PLAY_LOGIN = 0x29;                       // Join Game
    public const CB_PLAY_MOVE_ENTITY_POS = 0x2A;
    public const CB_PLAY_MOVE_ENTITY_POS_ROT = 0x2B;
    public const CB_PLAY_MOVE_VEHICLE = 0x2C;
    public const CB_PLAY_OPEN_SCREEN = 0x2E;
    public const CB_PLAY_PING = 0x30;
    public const CB_PLAY_PLACE_GHOST_RECIPE = 0x31;
    public const CB_PLAY_PLAYER_ABILITIES = 0x32;
    public const CB_PLAY_PLAYER_INFO_REMOVE = 0x37;
    public const CB_PLAY_PLAYER_INFO_UPDATE = 0x38;          // Player Info Add
    public const CB_PLAY_PLAYER_POSITION = 0x3A;             // Teleport (Position and Rotation)
    public const CB_PLAY_REMOVE_ENTITIES = 0x3E;
    public const CB_PLAY_RESOURCE_PACK = 0x40;
    public const CB_PLAY_RESPAWN = 0x41;                     // Spawn Position (Respawn)
    public const CB_PLAY_SECTION_BLOCKS_UPDATE = 0x43;       // Multi Block Change
    public const CB_PLAY_SERVER_DATA = 0x45;                 // Limited Packet Size
    public const CB_PLAY_SET_ACTION_BAR_TEXT = 0x46;
    public const CB_PLAY_SET_CAMERA = 0x4C;
    public const CB_PLAY_SET_CARRIED_ITEM = 0x4D;            // Held Item Change
    public const CB_PLAY_SET_DEFAULT_SPAWN_POSITION = 0x50;
    public const CB_PLAY_SET_ENTITY_MOTION = 0x54;           // Set Passengers-style motion
    public const CB_PLAY_SET_EQUIPMENT = 0x55;               // Player Composition
    public const CB_PLAY_SET_EXPERIENCE = 0x56;              // Set XP
    public const CB_PLAY_SET_HEALTH = 0x57;                  // Update Health
    public const CB_PLAY_SET_SIMULATION_DISTANCE = 0x5C;
    public const CB_PLAY_SET_SUBTITLE_TEXT = 0x5D;
    public const CB_PLAY_SET_TIME = 0x5E;                    // Time Update
    public const CB_PLAY_SET_TITLE_TEXT = 0x5F;
    public const CB_PLAY_STOP_SOUND = 0x63;
    public const CB_PLAY_SYSTEM_CHAT = 0x65;                 // System Chat Message
    public const CB_PLAY_UPDATE_ATTRIBUTES = 0x6B;
    public const CB_PLAY_UPDATE_ENABLED_FEATURES = 0x6C;     // Feature Flags
    public const CB_PLAY_UPDATE_TAGS = 0x70;                 // Tags

    // Алиасы legacy-имён (значения — точные ID Protocol 763):
    public const PLAY_JOIN_GAME = self::CB_PLAY_LOGIN;                      // 0x29
    public const PLAY_MAP_CHUNK = self::CB_PLAY_LEVEL_CHUNK_WITH_LIGHT;     // 0x23
    public const PLAY_CHUNK_DATA = self::CB_PLAY_LEVEL_CHUNK_WITH_LIGHT;    // 0x23
    public const PLAY_POSITION = self::CB_PLAY_PLAYER_POSITION;             // 0x3A
    public const PLAY_PLAYER_POSITION = self::CB_PLAY_PLAYER_POSITION;      // 0x3A
    public const PLAY_GAME_EVENT = self::CB_PLAY_GAME_EVENT;                // 0x21
    public const PLAY_KEEP_ALIVE = self::CB_PLAY_KEEP_ALIVE;                // 0x22
    public const PLAY_SYSTEM_CHAT = self::CB_PLAY_SYSTEM_CHAT;              // 0x65
    public const PLAY_DECLARE_COMMANDS = self::CB_PLAY_DECLARE_COMMANDS;    // 0x12
    public const PLAY_PLAYER_INFO_UPDATE = self::CB_PLAY_PLAYER_INFO_UPDATE; // 0x38

    private function __construct()
    {
    }
}
