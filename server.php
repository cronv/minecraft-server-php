<?php

declare(strict_types=1);

/**
 * Minecraft Java Edition 1.20.1 (Protocol 763) хаб-сервер на чистом PHP 8.2.
 *
 * Точка входа: php server.php
 *
 * Ключевое исправление (клиент 1.20.1 отваливался сразу после Login Success):
 *  - Сервер ожидал пакет Login Acknowledged (login C→S 0x03) и закрывал
 *    соединение через 5 секунд, если он не приходил. Login Acknowledged
 *    существует только начиная с Protocol 764 (Minecraft 1.20.2+): в
 *    Protocol 763 (1.20.1) клиенту НЕ нужно ничего подтверждать — после
 *    получения Login Success клиент 1.20.1 СРАЗУ переходит в состояние Play
 *    и первым своим Play-пакетом шлёт Client Information (SB Play 0x00).
 *    Теперь сервер сам переводит соединение в Play немедленно после отправки
 *    Login Success (для protocol >= 764 сохранено старое поведение с ожиданием
 *    ACK — см. LoginHandler::afterLoginSuccessAccepted).
 *  - Login Start (protocol 763): UUID читается строго по формату
 *    [packetId VarInt][username String][UUID 16 байт] (окно = ровно 16 байт
 *    после строки имени), а не эвристикой «последние 16 байт пакета».
 *  - Исправлен ID пакета Player Info Update: было 0x38, по эталону
 *    Protocol 763 (wiki.vg / minecraft-data 1.20.1) — 0x3A.
 *  - Исправлено кадрирование Chunk Data: chunkData должен кодироваться как
 *    беззнаковый byte[] ([VarInt длина][байты]), а не как signed-varint
 *    длина + сырые байты.
 *
 * Ключевые исправления относительно предыдущей версии:
 *  - NBTWriter писал имена тегов как VarInt-строки вместо обязательных
 *    unsigned short (2 байта, big-endian) — клиент не мог распарсить
 *    Registry Data / Join Game и «висел» в состоянии конфигурации.
 *  - Feature Flags отправлялся пустым списком; реестр биомов был неполным
 *    (64 записи вместо 66 с корректными id/именами).
 *  - В Play не отправлялись Server Difficulty, Player Info Update (remove),
 *    Empty Chunk Snapshot, Clientbound Set Default Spawn Position — без них
 *    клиент остаётся на экране «Connecting…».
 *  - Keep Alive в Play использовал ID 0x1F вместо 0x21; игнорирование
 *    пакетов клиента приводило к таймауту.
 *  - Убраны блокирующие usleep()-«паузы» между пакетами (событийный цикл).
 *
 * Паттерны: State (конечный автомат протокола), Strategy (построение NBT),
 * Flyweight (кэш сериализованных реестров), Facade (PacketFactory),
 * Chain of Responsibility (PacketDispatcher по состояниям),
 * Template Method (mainLoop сервера).
 */

// ============================================================================
// Протокольные константы (Protocol 763, Minecraft 1.20.1)
// ============================================================================

/**
 * ID пакетов Protocol 763. Разделены по направлениям, потому что в
 * Configuration/Play состояния S2C и C2S таблицы ID НЕ совпадают.
 */
/**
 * ВНИМАНИЕ: таблица ниже — ЭТАЛОН Protocol 763 (Minecraft 1.20.1).
 * Префиксы: CB_ = Clientbound (S→C), SB_ = Serverbound (C→S).
 * Разделённых состояний Configuration/Login ACK в 1.20.1 нет: переход
 * Login → Play происходит напрямую после SB_LOGIN_LOGIN_ACKNOWLEDGED (0x03).
 */
final class PacketId
{
    // =========================================================================
    // Handshake State
    // =========================================================================
    public const SB_HANDSHAKE = 0x00;         // Client → Server

    // =========================================================================
    // Status State
    // =========================================================================
    public const SB_STATUS_REQUEST = 0x00;    // Client → Server
    public const CB_STATUS_RESPONSE = 0x00;   // Server → Client
    public const SB_STATUS_PING = 0x01;       // Client → Server
    public const CB_STATUS_PONG = 0x01;       // Server → Client

    // =========================================================================
    // Login State (Server-bound, C→S)
    // =========================================================================
    public const SB_LOGIN_START = 0x00;                 // Бывший CS_LOGIN_START
    public const SB_LOGIN_ENCRYPTION_RESPONSE = 0x01;   // Client → Server
    public const SB_LOGIN_PLUGIN_RESPONSE = 0x02;       // Client → Server (Бывший CS_LOGIN_PLUGIN_RESPONSE)
    public const SB_LOGIN_LOGIN_ACKNOWLEDGED = 0x03;    // Client → Server (Бывший CS_LOGIN_ACKNOWLEDGED)

    // =========================================================================
    // Login State (Client-bound, S→C)
    // =========================================================================
    public const CB_LOGIN_DISCONNECT = 0x00;            // Server → Client
    public const CB_LOGIN_ENCRYPTION_REQUEST = 0x01;    // Server → Client
    public const CB_LOGIN_SUCCESS = 0x02;               // Server → Client
    public const CB_LOGIN_SET_COMPRESSION = 0x03;       // Server → Client
    public const CB_LOGIN_PLUGIN_REQUEST = 0x04;        // Server → Client

    // =========================================================================
    // Configuration State (в Protocol 763 не используется; таблица приведена
    // для соответствия документации Protocol 763/764 и будущих версий)
    // =========================================================================
    // Clientbound Configuration (Server → Client)
    public const CB_CONFIG_PLUGIN_MESSAGE = 0x00;
    public const CB_CONFIG_DISCONNECT = 0x01;
    public const CB_CONFIG_FINISH_CONFIGURATION = 0x02;
    public const CB_CONFIG_KEEP_ALIVE = 0x03;
    public const CB_CONFIG_PING = 0x04;
    public const CB_CONFIG_RESET_CHAT = 0x05;
    public const CB_CONFIG_REGISTRY_DATA = 0x06;
    public const CB_CONFIG_REMOVE_RESOURCE_PACK = 0x07;
    public const CB_CONFIG_ADD_RESOURCE_PACK = 0x08;
    public const CB_CONFIG_STORE_COOKIE = 0x09;
    public const CB_CONFIG_TRANSFER = 0x0A;
    public const CB_CONFIG_UPDATE_ENABLED_FEATURES = 0x0B;
    public const CB_CONFIG_UPDATE_TAGS = 0x0C;
    public const CB_CONFIG_KNOWN_PACKS = 0x0D;

    // Serverbound Configuration (Client → Server)
    public const SB_CONFIG_CLIENT_INFORMATION = 0x00;
    /** Короткий алиас (Protocol 763): Client Information -> "Client Info". */
    public const SB_CONFIG_CLIENT_INFO = self::SB_CONFIG_CLIENT_INFORMATION;   // 0x00
    public const SB_CONFIG_PLUGIN_MESSAGE = 0x01;
    public const SB_CONFIG_ACKNOWLEDGE_FINISH_CONFIGURATION = 0x02;
    public const SB_CONFIG_KEEP_ALIVE = 0x03;
    public const SB_CONFIG_PONG = 0x04;
    public const SB_CONFIG_RESOURCE_PACK = 0x05;
    public const SB_CONFIG_KNOWN_PACKS = 0x06;

    // =========================================================================
    // Play State — Clientbound (Server → Client), Protocol 763
    // =========================================================================
    public const CB_PLAY_BUNDLE_DELIMITER = 0x00;
    public const CB_PLAY_ADD_ENTITY = 0x01;
    public const CB_PLAY_ADD_EXPERIENCE_ORB = 0x02;
    public const CB_PLAY_ADD_PLAYER = 0x03;
    public const CB_PLAY_ANIMATE = 0x04;
    public const CB_PLAY_AWARD_STATISTICS = 0x05;
    public const CB_PLAY_BLOCK_CHANGED_ACK = 0x06;
    public const CB_PLAY_BLOCK_DESTRUCTION = 0x07;
    public const CB_PLAY_BLOCK_ENTITY_DATA = 0x08;
    public const CB_PLAY_BLOCK_EVENT = 0x09;
    public const CB_PLAY_BLOCK_UPDATE = 0x0A;
    public const CB_PLAY_BOSS_EVENT = 0x0B;
    public const CB_PLAY_CHANGE_DIFFICULTY = 0x0C;
    public const CB_PLAY_CHUNK_BATCH_FINISHED = 0x0D;
    public const CB_PLAY_CHUNK_BATCH_START = 0x0E;
    public const CB_PLAY_CHUNK_BIOMES = 0x0F;
    public const CB_PLAY_CLEAR_TITLES = 0x10;
    public const CB_PLAY_COMMAND_SUGGESTIONS = 0x11;
    public const CB_PLAY_COMMANDS = 0x12;
    public const CB_PLAY_CONTAINER_CLOSE = 0x13;
    public const CB_PLAY_CONTAINER_SET_CONTENT = 0x14;
    public const CB_PLAY_CONTAINER_SET_DATA = 0x15;
    public const CB_PLAY_CONTAINER_SET_SLOT = 0x16;
    public const CB_PLAY_COOLDOWN = 0x17;
    public const CB_PLAY_CUSTOM_CHAT_COMPLETIONS = 0x18;
    public const CB_PLAY_CUSTOM_PAYLOAD = 0x19;
    public const CB_PLAY_DAMAGE_EVENT = 0x1A;
    public const CB_PLAY_DELETE_CHAT = 0x1B;
    public const CB_PLAY_DISCONNECT = 0x1C;
    public const CB_PLAY_DISGUISED_CHAT = 0x1D;
    public const CB_PLAY_ENTITY_EVENT = 0x1E;
    public const CB_PLAY_EXPLOSION = 0x1F;
    public const CB_PLAY_FORGET_LEVEL_CHUNK = 0x20;
    public const CB_PLAY_GAME_EVENT = 0x21;
    public const CB_PLAY_KEEP_ALIVE = 0x22;
    public const CB_PLAY_LEVEL_CHUNK_WITH_LIGHT = 0x23;
    public const CB_PLAY_LEVEL_EVENT = 0x24;
    public const CB_PLAY_LEVEL_PARTICLES = 0x25;
    public const CB_PLAY_LIGHT_UPDATE = 0x26;
    /** Protocol 763: Set Container Content (обновление инвентаря), НЕ Join Game! */
    public const CB_PLAY_SET_CONTAINER_CONTENT = 0x27;
    public const CB_PLAY_MAP_ITEM_DATA = 0x28;
    /** Protocol 763: Login (Join Game) — ИМЕННО 0x29 (было ошибочно 0x27). */
    public const CB_PLAY_LOGIN = 0x29; // БЫЛО 0x27, СТАЛО 0x29 (Join Game)
    public const CB_PLAY_MOVE_ENTITY_POS = 0x2A;
    public const CB_PLAY_MOVE_ENTITY_POS_ROT = 0x2B;
    public const CB_PLAY_MOVE_VEHICLE = 0x2C;
    public const CB_PLAY_OPEN_BOOK = 0x2D;
    public const CB_PLAY_OPEN_SCREEN = 0x2E;
    public const CB_PLAY_OPEN_SIGN_EDITOR = 0x2F;
    public const CB_PLAY_PING = 0x30;
    public const CB_PLAY_PLACE_GHOST_RECIPE = 0x31;
    public const CB_PLAY_PLAYER_ABILITIES = 0x32;
    public const CB_PLAY_PLAYER_CHAT = 0x33;
    public const CB_PLAY_PLAYER_COMBAT_END = 0x34;
    public const CB_PLAY_PLAYER_COMBAT_ENTER = 0x35;
    public const CB_PLAY_PLAYER_COMBAT_KILL = 0x36;
    public const CB_PLAY_PLAYER_INFO_REMOVE = 0x37;
    public const CB_PLAY_PLAYER_INFO_UPDATE = 0x38;
    public const CB_PLAY_PLAYER_LOOK_AT = 0x39;
    public const CB_PLAY_PLAYER_POSITION = 0x3A;
    public const CB_PLAY_RECIPE_BOOK_ADD = 0x3B;
    public const CB_PLAY_RECIPE_BOOK_REMOVE = 0x3C;
    public const CB_PLAY_RECIPE_BOOK_SETTINGS = 0x3D;
    public const CB_PLAY_REMOVE_ENTITIES = 0x3E;
    public const CB_PLAY_REMOVE_ENTITY_EFFECT = 0x3F;
    public const CB_PLAY_RESOURCE_PACK = 0x40;
    public const CB_PLAY_RESPAWN = 0x41;
    public const CB_PLAY_ROTATE_HEAD = 0x42;
    public const CB_PLAY_SECTION_BLOCKS_UPDATE = 0x43;
    public const CB_PLAY_SELECT_ADVANCEMENTS_TAB = 0x44;
    public const CB_PLAY_SERVER_DATA = 0x45;
    public const CB_PLAY_SET_ACTION_BAR_TEXT = 0x46;
    public const CB_PLAY_SET_BORDER_CENTER = 0x47;
    public const CB_PLAY_SET_BORDER_LERP_SIZE = 0x48;
    public const CB_PLAY_SET_BORDER_SIZE = 0x49;
    public const CB_PLAY_SET_BORDER_WARNING_DELAY = 0x4A;
    public const CB_PLAY_SET_BORDER_WARNING_DISTANCE = 0x4B;
    public const CB_PLAY_SET_CAMERA = 0x4C;
    public const CB_PLAY_SET_CARRIED_ITEM = 0x4D;
    public const CB_PLAY_SET_CHUNK_CACHE_CENTER = 0x4E;
    public const CB_PLAY_SET_CHUNK_CACHE_RADIUS = 0x4F;
    public const CB_PLAY_SET_DEFAULT_SPAWN_POSITION = 0x50;
    public const CB_PLAY_SET_DISPLAY_OBJECTIVE = 0x51;
    public const CB_PLAY_SET_ENTITY_DATA = 0x52;
    public const CB_PLAY_SET_ENTITY_LINK = 0x53;
    public const CB_PLAY_SET_ENTITY_MOTION = 0x54;
    public const CB_PLAY_SET_EQUIPMENT = 0x55;
    public const CB_PLAY_SET_EXPERIENCE = 0x56;
    public const CB_PLAY_SET_HEALTH = 0x57;
    public const CB_PLAY_SET_OBJECTIVE = 0x58;
    public const CB_PLAY_SET_PASSENGERS = 0x59;
    public const CB_PLAY_SET_PLAYER_TEAM = 0x5A;
    public const CB_PLAY_SET_SCORE = 0x5B;
    public const CB_PLAY_SET_SIMULATION_DISTANCE = 0x5C;
    public const CB_PLAY_SET_SUBTITLE_TEXT = 0x5D;
    public const CB_PLAY_SET_TIME = 0x5E;
    public const CB_PLAY_SET_TITLE_TEXT = 0x5F;
    public const CB_PLAY_SET_TITLES_ANIMATION = 0x60;
    public const CB_PLAY_SOUND_ENTITY = 0x61;
    public const CB_PLAY_SOUND = 0x62;
    public const CB_PLAY_STOP_SOUND = 0x63;
    public const CB_PLAY_STORE_COOKIE = 0x64;
    public const CB_PLAY_SYSTEM_CHAT = 0x65;
    public const CB_PLAY_TAB_LIST = 0x66;
    public const CB_PLAY_TAG_QUERY = 0x67;
    public const CB_PLAY_TELEPORT_ENTITY = 0x68;
    public const CB_PLAY_TRANSFER = 0x69;
    public const CB_PLAY_UPDATE_ADVANCEMENTS = 0x6A;
    public const CB_PLAY_UPDATE_ATTRIBUTES = 0x6B;
    public const CB_PLAY_UPDATE_ENABLED_FEATURES = 0x6C;
    public const CB_PLAY_UPDATE_ENTITY_POSITION = 0x6D;
    public const CB_PLAY_UPDATE_MOB_EFFECT = 0x6E;
    public const CB_PLAY_UPDATE_RECIPES = 0x6F;
    public const CB_PLAY_UPDATE_TAGS = 0x70;

    // =========================================================================
    // Play State — Serverbound (Client → Server), Protocol 763
    // =========================================================================
    public const SB_PLAY_ACCEPT_TELEPORTATION = 0x00;
    public const SB_PLAY_BLOCK_ENTITY_TAG_QUERY = 0x01;
    public const SB_PLAY_CHANGE_BOOK_PAGE = 0x02;
    public const SB_PLAY_CHAT_ACK = 0x03;
    public const SB_PLAY_CHAT_COMMAND = 0x04;
    public const SB_PLAY_CHAT_COMMAND_SIGNED = 0x05;
    public const SB_PLAY_CHAT = 0x06;
    public const SB_PLAY_CHAT_SESSION_UPDATE = 0x07;
    public const SB_PLAY_CHUNK_BATCH_RECEIVED = 0x08;
    public const SB_PLAY_CLIENT_COMMAND = 0x09;
    public const SB_PLAY_CLIENT_INFORMATION = 0x0A;
    public const SB_PLAY_COMMAND_SUGGESTIONS = 0x0B;
    public const SB_PLAY_CONTAINER_CLOSE = 0x0C;
    public const SB_PLAY_CONTAINER_CLICK = 0x0D;
    public const SB_PLAY_CONTAINER_BUTTON_CLICK = 0x0E;
    public const SB_PLAY_SET_CREATIVE_MODE_SLOT = 0x0F;
    public const SB_PLAY_CUSTOM_PAYLOAD = 0x10;
    public const SB_PLAY_EDIT_BOOK = 0x11;
    public const SB_PLAY_ENTITY_TAG_QUERY = 0x12;
    public const SB_PLAY_INTERACT = 0x13;
    public const SB_PLAY_JIGSAW_GENERATE = 0x14;
    public const SB_PLAY_KEEP_ALIVE = 0x15;
    public const SB_PLAY_LOCK_DIFFICULTY = 0x16;
    public const SB_PLAY_MOVE_PLAYER_POS = 0x17;
    public const SB_PLAY_MOVE_PLAYER_POS_ROT = 0x18;
    public const SB_PLAY_MOVE_PLAYER_ROT = 0x19;
    public const SB_PLAY_MOVE_PLAYER_STATUS_ONLY = 0x1A;
    public const SB_PLAY_MOVE_VEHICLE = 0x1B;
    public const SB_PLAY_PADDLE_BOAT = 0x1C;
    public const SB_PLAY_PICK_ITEM = 0x1D;
    public const SB_PLAY_PLACE_RECIPE = 0x1E;
    public const SB_PLAY_PLAYER_ABILITIES = 0x1F;
    public const SB_PLAY_PLAYER_ACTION = 0x20;
    public const SB_PLAY_PLAYER_COMMAND = 0x21;
    public const SB_PLAY_PLAYER_INPUT = 0x22;
    public const SB_PLAY_PONG = 0x23;
    public const SB_PLAY_RECIPE_BOOK_CHANGE_SETTINGS = 0x24;
    public const SB_PLAY_RECIPE_BOOK_SEEN_RECIPE = 0x25;
    public const SB_PLAY_RENAME_ITEM = 0x26;
    public const SB_PLAY_RESOURCE_PACK = 0x27;
    public const SB_PLAY_SEEN_ADVANCEMENTS = 0x28;
    public const SB_PLAY_SELECT_TRADE = 0x29;
    public const SB_PLAY_SET_BEACON = 0x2A;
    public const SB_PLAY_SET_CARRIED_ITEM = 0x2B;
    public const SB_PLAY_SET_COMMAND_BLOCK = 0x2C;
    public const SB_PLAY_SET_COMMAND_MINECART = 0x2D;
    public const SB_PLAY_SET_HELD_ITEM = 0x2E;
    public const SB_PLAY_SET_JIGSAW_BLOCK = 0x2F;
    public const SB_PLAY_SET_STRUCTURE_BLOCK = 0x30;
    public const SB_PLAY_SIGN_UPDATE = 0x31;
    public const SB_PLAY_SWING = 0x32;
    public const SB_PLAY_TELEPORT_TO_ENTITY = 0x33;
    public const SB_PLAY_USE_ITEM_ON = 0x34;
    public const SB_PLAY_USE_ITEM = 0x35;

    // =========================================================================
    // Обратная совместимость: старые имена (CS_* / PLAY_* / CONFIG_* / LOGIN_*)
    // сохраняются как алиасы канонических констант Protocol 763, чтобы весь
    // существующий код server.php продолжал работать без изменений вызовов.
    // ЗНАЧЕНИЯ исправлены на точные ID из Protocol 763!
    // =========================================================================

    // --- Старые Server-bound (C→S) алиасы → SB_* ---

    public const CS_CONFIG_PLUGIN_MESSAGE = self::SB_CONFIG_PLUGIN_MESSAGE;                     // 0x01
    public const CS_CONFIG_FINISHED_ACK = self::SB_CONFIG_ACKNOWLEDGE_FINISH_CONFIGURATION;     // 0x02
    public const CS_CONFIG_KEEP_ALIVE = self::SB_CONFIG_KEEP_ALIVE;                             // 0x03
    public const CS_CONFIG_PONG = self::SB_CONFIG_PONG;                                         // 0x04
    public const CS_CONFIG_RESOURCE_PACK_STATUS = self::SB_CONFIG_RESOURCE_PACK;                // 0x05
    public const CS_CONFIG_KNOWN_PACKS = self::SB_CONFIG_KNOWN_PACKS;                            // 0x06
    public const CS_CONFIG_SELECT_KNOWN_PACKS = self::SB_CONFIG_KNOWN_PACKS;                     // 0x06
    public const CS_CONFIG_CLIENT_INFORMATION = self::SB_CONFIG_CLIENT_INFORMATION;              // 0x00
    public const CS_CONFIG_GENERATE_STRUCTURE = 0x07; // вне таблицы 763 (удалён) — заглушка
    public const CS_CONFIG_DELETE_STRUCTURE = 0x08;  // вне таблицы 763 (удалён) — заглушка
    public const CS_CONFIG_MODERN_BRAND = 0x09;    // вне таблицы 763 (удалён) — заглушка

    public const CS_PLAY_CHAT_COMMAND = self::SB_PLAY_CHAT_COMMAND;              // 0x04
    public const CS_PLAY_SETTINGS = self::SB_PLAY_CLIENT_INFORMATION;            // 0x0A
    public const CS_PLAY_WORLD_INTERACTION = self::SB_PLAY_USE_ITEM_ON;          // 0x34
    public const CS_PLAY_SWING_ARM = self::SB_PLAY_SWING;                        // 0x32
    public const CS_PLAY_KEEP_ALIVE = self::SB_PLAY_KEEP_ALIVE;                  // 0x15
    public const CS_PLAY_PONG = self::SB_PLAY_PONG;                              // 0x23
    public const CS_PLAY_CUSTOM_PAYLOAD = self::SB_PLAY_CUSTOM_PAYLOAD;          // 0x10

    // --- Старые Client-bound (S→C) алиасы → CB_* (значения исправлены!) ---

    public const CONFIG_KEEP_ALIVE = self::CB_CONFIG_KEEP_ALIVE;                 // 0x03
    public const CONFIG_PING = self::CB_CONFIG_PING;                             // 0x04
    public const CONFIG_PLUGIN_MESSAGE = self::CB_CONFIG_PLUGIN_MESSAGE;         // 0x00
    public const CONFIG_DISCONNECT = self::CB_CONFIG_DISCONNECT;                 // 0x01
    public const CONFIG_FINISH_CONFIGURATION = self::CB_CONFIG_FINISH_CONFIGURATION; // 0x02
    public const CONFIG_RESOURCE_PACK = self::CB_CONFIG_ADD_RESOURCE_PACK;       // 0x08
    public const CONFIG_TRANSFER_HANDLER = self::CB_CONFIG_TRANSFER;             // 0x0A
    public const CONFIG_FEATURE_FLAGS = self::CB_CONFIG_UPDATE_ENABLED_FEATURES; // 0x0B
    public const CONFIG_REGISTRY_DATA = self::CB_CONFIG_REGISTRY_DATA;           // 0x06
    public const CONFIG_REMOVE_RESOURCE_PACK = self::CB_CONFIG_REMOVE_RESOURCE_PACK; // 0x07
    public const CONFIG_UPDATE_TAGS = self::CB_CONFIG_UPDATE_TAGS;               // 0x0C
    public const CONFIG_KNOWN_PACKS = self::CB_CONFIG_KNOWN_PACKS;               // 0x0D

    public const PLAY_SPAWN_ENTITY = self::CB_PLAY_ADD_ENTITY;                   // 0x01
    public const PLAY_DECLARE_COMMANDS = self::CB_PLAY_COMMANDS;                 // 0x12
    public const PLAY_MULTI_BLOCK_CHANGE = self::CB_PLAY_SECTION_BLOCKS_UPDATE;  // 0x43
    public const PLAY_GAME_EVENT = self::CB_PLAY_GAME_EVENT;                     // 0x21
    public const PLAY_EMPTY_CHUNK_SNAPSHOT = self::CB_PLAY_CHUNK_BATCH_FINISHED; // 0x0D
    public const PLAY_SET_XP = self::CB_PLAY_SET_EXPERIENCE;                     // 0x56
    public const PLAY_INITIALIZE_WINDOW = self::CB_PLAY_CONTAINER_SET_CONTENT;   // 0x14
    public const PLAY_KEEP_ALIVE = self::CB_PLAY_KEEP_ALIVE;                     // 0x22
    public const PLAY_WORLD_EVENTS = self::CB_PLAY_LEVEL_EVENT;                  // 0x24
    public const PLAY_SECTION_BLOCKS_UPDATE = self::CB_PLAY_SECTION_BLOCKS_UPDATE; // 0x43
    public const PLAY_SYSTEM_CHAT_MESSAGE = self::CB_PLAY_SYSTEM_CHAT;           // 0x65
    public const PLAY_PLAYER_INFO = self::CB_PLAY_PLAYER_INFO_UPDATE;            // 0x38
    public const PLAY_LIMITED_PACKET_SIZE = self::CB_PLAY_SERVER_DATA;           // 0x45
    public const PLAY_SET_TITLE_TEXT = self::CB_PLAY_SET_TITLE_TEXT;             // 0x5F
    public const PLAY_ADD_PLAYER = self::CB_PLAY_ADD_PLAYER;                     // 0x03
    public const PLAY_JOIN_GAME = self::CB_PLAY_LOGIN;                           // 0x29 (Join Game, Protocol 763)
    public const PLAY_PLAYER_POSITION = self::CB_PLAY_PLAYER_POSITION;           // 0x3A
    public const PLAY_PLAYER_ABILITIES = self::CB_PLAY_PLAYER_ABILITIES;         // 0x32
    public const PLAY_PLAYER_COMPOSITION = self::CB_PLAY_SET_EQUIPMENT;          // 0x55
    public const PLAY_SET_HEALTH = self::CB_PLAY_SET_HEALTH;                     // 0x57
    public const PLAY_SET_PASSIVES = self::CB_PLAY_SET_ENTITY_MOTION;            // 0x54
    public const PLAY_HURT_ARMOR = self::CB_PLAY_DAMAGE_EVENT;                   // 0x1A
    public const PLAY_SERVER_DIFFICULTY = self::CB_PLAY_CHANGE_DIFFICULTY;       // 0x0C
    public const PLAY_SIMULATION_DISTANCE = self::CB_PLAY_SET_SIMULATION_DISTANCE; // 0x5C
    public const PLAY_POSITION_SYNC = self::CB_PLAY_MOVE_ENTITY_POS;             // 0x2A
    public const PLAY_SET_SLOT = self::CB_PLAY_CONTAINER_SET_SLOT;               // 0x16
    public const PLAY_ACTIONBAR_TEXT = self::CB_PLAY_SET_ACTION_BAR_TEXT;        // 0x46
    public const PLAY_SET_DEFAULT_SPAWN_POSITION = self::CB_PLAY_SET_DEFAULT_SPAWN_POSITION; // 0x50
    public const PLAY_MAP_ID = self::CB_PLAY_MAP_ITEM_DATA;                      // 0x28
    public const PLAY_REQUEST_PERMISSIONS = self::CB_PLAY_SET_CAMERA;            // 0x4C
    public const PLAY_TITLE_SUBTITLE = self::CB_PLAY_SET_SUBTITLE_TEXT;          // 0x5D
    public const PLAY_TIME_UPDATE = self::CB_PLAY_SET_TIME;                      // 0x5E
    public const PLAY_SET_CRAFT_RESULT = self::CB_PLAY_PLACE_GHOST_RECIPE;       // 0x31
    public const PLAY_PLUGIN_MESSAGE = self::CB_PLAY_CUSTOM_PAYLOAD;             // 0x19
    public const PLAY_ACKNOWLEDGE_CHUNKS = self::CB_PLAY_BLOCK_CHANGED_ACK;      // 0x06
    public const PLAY_CHUNK_DATA = self::CB_PLAY_LEVEL_CHUNK_WITH_LIGHT;          // 0x23
    public const PLAY_UNLOAD_CHUNK = self::CB_PLAY_FORGET_LEVEL_CHUNK;            // 0x20
    public const PLAY_BLOCK_UPDATE = self::CB_PLAY_BLOCK_UPDATE;                  // 0x0A
    public const PLAY_HELD_ITEM_CHANGE = self::CB_PLAY_SET_CARRIED_ITEM;          // 0x4D
    public const PLAY_CLOSE_CONTAINER = self::CB_PLAY_CONTAINER_CLOSE;            // 0x13
    public const PLAY_OPEN_SCREEN = self::CB_PLAY_OPEN_SCREEN;                    // 0x2E
    public const PLAY_CONTAINER_PROPERTY = self::CB_PLAY_CONTAINER_SET_DATA;      // 0x15
    public const PLAY_DELETE_ENTITY = self::CB_PLAY_REMOVE_ENTITIES;              // 0x3E
    public const PLAY_CHANGE_GAME_MODE = self::CB_PLAY_PLAYER_INFO_UPDATE;        // 0x38 (нет отдельного пакета в 763)
    public const PLAY_SET_COOLDOWN = self::CB_PLAY_COOLDOWN;                      // 0x17
    public const PLAY_CUSTOM_PAYLOAD = self::CB_PLAY_CUSTOM_PAYLOAD;              // 0x19
    public const PLAY_STOP_SOUND = self::CB_PLAY_STOP_SOUND;                      // 0x63
    public const PLAY_CLEAR_TITLES = self::CB_PLAY_CLEAR_TITLES;                  // 0x10
    public const PLAY_REMOVE_RESOURCE_PACK = self::CB_PLAY_RESOURCE_PACK;         // 0x40

    public const PLAY_DIFFICULTY = self::PLAY_SERVER_DIFFICULTY;                 // 0x0C
    public const PLAY_UPDATE_TIME = self::PLAY_TIME_UPDATE;                      // 0x5E
    public const PLAY_SPAWN_POSITION = self::PLAY_SET_DEFAULT_SPAWN_POSITION;    // 0x50
    public const PLAY_POSITION = self::PLAY_PLAYER_POSITION;                     // 0x3A
    public const PLAY_ABILITIES = self::PLAY_PLAYER_ABILITIES;                   // 0x32
    public const PLAY_HELD_ITEM_SLOT = self::PLAY_HELD_ITEM_CHANGE;              // 0x4D
    public const PLAY_UPDATE_HEALTH = self::PLAY_SET_HEALTH;                     // 0x57
    public const PLAY_EXPERIENCE = self::PLAY_SET_XP;                            // 0x56
    public const PLAY_HURT_ANIMATION = self::PLAY_HURT_ARMOR;                    // 0x1A
    public const PLAY_SYSTEM_CHAT = self::PLAY_SYSTEM_CHAT_MESSAGE;              // 0x65
    public const PLAY_MAP_CHUNK = self::PLAY_CHUNK_DATA;                         // 0x23

    private function __construct()
    {
    }
}

/**
 * Состояния протокола (State pattern).
 */
enum PacketState: string
{
    case Handshake = 'handshake';
    case Status = 'status';
    case Login = 'login';
    // Configuration state существует только с Protocol 764 (1.20.2+);
    // в Protocol 763 (1.20.1) оно не используется — переход Login → Play
    // происходит напрямую после Login Acknowledged (0x03). Состояние
    // объявлено для полноты конечного автомата и будущих версий.
    case Configuration = 'configuration';
    case Play = 'play';
}

// Совместимость: прежний код обращался к имени ProtocolState.
class_alias('PacketState', 'ProtocolState');

// ============================================================================
// Логирование
// ============================================================================

/**
 * Утилита низкоуровневого кадрирования пакетов Minecraft (без состояния):
 * [VarInt length][VarInt packetId][data...]. Используется сервером,
 * тестовым клиентом и самотестами.
 */
final class PacketCodec
{
    /** Оборачивает данные пакета в кадр (length + packetId + payload). */
    public static function wrapPacket(int $packetId, string $data): string
    {
        $payload = VarInt::write($packetId) . $data;

        return VarInt::write(strlen($payload)) . $payload;
    }

    /** Разворачивает кадр обратно в [packetId, data] или null при битом кадре. */
    public static function unwrapPacket(string $frame): ?array
    {
        $offset = 0;
        $len = VarInt::read($frame, $offset);

        if ($len === null || $offset + $len > strlen($frame)) {
            return null;
        }

        $payload = substr($frame, $offset, $len);
        $pOffset = 0;
        $packetId = VarInt::read($payload, $pOffset);

        if ($packetId === null) {
            return null;
        }

        return [$packetId, substr($payload, $pOffset)];
    }
}

final class PacketLogger
{
    private static string $logFile = 'packets.log';

    /** @var resource|null Поток лога открывается лениво и переиспользуется. */
    private static mixed $logStream = null;

    public static function logPacket(string $direction, int $clientId, int $packetId, string $data): void
    {
        $entry = sprintf(
            "[%s] %s | Client %d | Packet 0x%02x (%s) | Length: %d bytes\n%s\n",
            date('Y-m-d H:i:s.u'),
            $direction,
            $clientId,
            $packetId,
            self::getPacketName($packetId),
            strlen($data),
            self::formatHex(bin2hex($data))
        );
        self::writeToStream($entry);
    }

    private static function formatHex(string $hex): string
    {
        $result = '';
        $length = strlen($hex);
        for ($i = 0; $i < $length; $i += 32) {
            $chunk = substr($hex, $i, 32);
            $result .= '  ' . implode(' ', str_split($chunk, 2)) . "\n";
        }

        return rtrim($result);
    }

    public static function getPacketName(int $packetId): string
    {
        // Human-readable имена для часто используемых ID Protocol 763.
        // Таблица справочная (для логов), направление уточняется состоянием.
        $names = [
            // Handshake / Status
            0x00 => 'Handshake / Status Request / SB Login Start / CB Status Response',
            0x01 => 'Status Ping / CB Login Encryption Request / SB Login Encryption Response',
            0x02 => 'CB Login Success / SB Login Plugin Response',
            0x03 => 'CB Login Set Compression / SB Login Acknowledged',
            0x04 => 'CB Login Plugin Request',

            // Configuration (S→C)
            0x05 => 'SB Play: Chat (Signed)',

            // Serverbound Play (C→S)
            0x06 => 'SB Play: Chat',
            0x0A => 'SB Play: Client Information (Settings)',
            0x10 => 'SB Play: Custom Payload (Plugin Message)',
            0x15 => 'SB Play: Keep Alive',
            0x17 => 'SB Play: Move Player Pos',
            0x18 => 'SB Play: Move Player Pos Rot',
            0x19 => 'SB Play: Move Player Rot',
            0x1A => 'SB Play: Move Player Status Only',
            0x23 => 'SB Play: Pong',

            // Clientbound Play (S→C)
            0x0D => 'CB Play: Chunk Batch Finished (Empty Snapshot)',
            0x12 => 'CB Play: Commands (Declare Commands)',
            0x19 => 'CB Play: Custom Payload (Plugin Message)',
            0x1A => 'CB Play: Damage Event (Hurt Animation)',
            0x21 => 'CB Play: Game Event',
            0x22 => 'CB Play: Keep Alive',
            0x23 => 'CB Play: Level Chunk With Light (Chunk Data)',
            0x27 => 'CB Play: Set Container Content',
            0x29 => 'CB Play: Login (Join Game)',
            0x2A => 'CB Play: Move Entity Pos',
            0x32 => 'CB Play: Player Abilities',
            0x37 => 'CB Play: Player Info Remove',
            0x38 => 'CB Play: Player Info Update (Add)',
            0x3A => 'CB Play: Player Position (Teleport)',
            0x4D => 'CB Play: Set Carried Item (Held Item Change)',
            0x50 => 'CB Play: Set Default Spawn Position',
            0x56 => 'CB Play: Set Experience',
            0x57 => 'CB Play: Set Health',
            0x5C => 'CB Play: Set Simulation Distance',
            0x5E => 'CB Play: Set Time (Time Update)',
            0x65 => 'CB Play: System Chat',
            0x0C => 'CB Play: Change Difficulty (Server Difficulty)',
        ];

        return $names[$packetId] ?? 'Unknown';
    }

    public static function clearLog(): void
    {
        self::closeStream();
        if (file_exists(self::$logFile)) {
            unlink(self::$logFile);
        }
    }

    public static function logError(string $message): void
    {
        self::writeToStream(sprintf("[%s] ERROR: %s\n", date('Y-m-d H:i:s.u'), $message));
        echo "❌ $message\n";
    }

    public static function logInfo(string $message): void
    {
        echo "ℹ️ $message\n";
        self::flushStdout();
    }

    /** Немедленно сбрасывает stdout в файл/терминал (иначе при перенаправлении
     *  в файл PHP буферизует вывод и живые логи недоступны до выхода процесса). */
    public static function flushStdout(): void
    {
        if (function_exists('flush')) {
            @flush();
        }
        if (function_exists('ob_flush') && ob_get_length() !== false) {
            @ob_flush();
        }
    }

    public static function logDebug(string $message): void
    {
        echo "🔍 $message\n";
    }

    public static function logWarning(string $message): void
    {
        echo "⚠️ $message\n";
        self::flushStdout();
    }

    public static function logConnection(string $message): void
    {
        echo "🌐 $message\n";
    }

    /**
     * Ленивое открытие файла лога: поток создаётся один раз и держится
     * открытым — без syscall open/append на каждый пакет.
     */
    private static function writeToStream(string $entry): void
    {
        if (!is_resource(self::$logStream)) {
            $stream = @fopen(self::$logFile, 'ab');
            if ($stream === false) {
                return;
            }
            self::$logStream = $stream;
        }

        @fwrite(self::$logStream, $entry);
    }

    private static function closeStream(): void
    {
        if (is_resource(self::$logStream)) {
            fclose(self::$logStream);
        }
        self::$logStream = null;
    }
}

// ============================================================================
// Примитивы протокола
// ============================================================================

final class VarInt
{
    public static function read(string $buffer, int &$offset): ?int
    {
        $value = 0;
        $shift = 0;

        do {
            if ($offset >= strlen($buffer)) {
                return null;
            }

            $byte = ord($buffer[$offset++]);
            $value |= ($byte & 0x7F) << $shift;
            $shift += 7;

            if ($shift > 35) {
                throw new RuntimeException('VarInt too large');
            }
        } while (($byte & 0x80) !== 0);

        return $value;
    }

    public static function write(int $value): string
    {
        // Java-int semantics: маскируем до 32 бит (для отрицательных значений).
        $value &= 0xFFFFFFFF;
        $bytes = '';

        do {
            $temp = $value & 0x7F;
            $value >>= 7;

            if ($value !== 0) {
                $temp |= 0x80;
            }

            $bytes .= chr($temp);
        } while ($value !== 0);

        return $bytes;
    }
}

final class StringHandler
{
    public static function read(string $buffer, int &$offset): string
    {
        $length = VarInt::read($buffer, $offset);
        if ($length === null || $length === 0 || $offset + $length > strlen($buffer)) {
            return '';
        }

        $string = substr($buffer, $offset, $length);
        $offset += $length;

        return $string;
    }

    public static function write(string $string): string
    {
        return VarInt::write(strlen($string)) . $string;
    }
}


final class Uuid
{
    /** Генерирует offline-UUID игрока (Java: UUID.nameUUIDFromBytes("MinecraftProfile:"+name), v3 MD5). */
    public static function fromUsername(string $username): string
    {
        $md5 = md5('MinecraftProfile:' . $username, true);
        $md5[6] = chr((ord($md5[6]) & 0x0F) | 0x30); // version 3
        $md5[8] = chr((ord($md5[8]) & 0x3F) | 0x80); // IETF variant

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($md5), 4));
    }

    public static function toBinary(string $uuid): string
    {
        return hex2bin(str_replace('-', '', $uuid));
    }

    public static function pretty(string $binary): string
    {
        $h = bin2hex($binary);

        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4)
            . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
    }
}
final class Nbt
{
    public const T_END = 0;
    public const T_BYTE = 1;
    public const T_SHORT = 2;
    public const T_INT = 3;
    public const T_LONG = 4;
    public const T_FLOAT = 5;
    public const T_DOUBLE = 6;
    public const T_STRING = 8;
    public const T_LIST = 9;
    public const T_COMPOUND = 10;
    public const T_LONG_ARRAY = 12;

    /** @var string Аккумулирующий байтовый буфер. */
    public string $buf = '';

    /** Собирает корневой компаунд (Network NBT: type + пустое имя). */
    public static function root(callable $fn): string
    {
        $nbt = new self();
        $nbt->compound('', $fn);

        return $nbt->buf;
    }

    /** Разворачивает ассоциативный массив скаляров в NBT-компаунд (рекурсивно). */
    public function fromArray(string $name, array $value): self
    {
        $this->compound($name, function (Nbt $n) use ($value): void {
            foreach ($value as $k => $v) {
                $n->writeValue((string) $k, $v);
            }
        });

        return $this;
    }

    private function writeValue(string $name, mixed $value): void
    {
        if (is_array($value)) {
            $this->fromArray($name, $value);

            return;
        }

        match (true) {
            is_bool($value) => $this->byte($name, $value ? 1 : 0),
            is_int($value) => $this->int($name, $value),
            is_float($value) => $this->double($name, $value),
            is_string($value) => $this->string($name, $value),
            default => throw new InvalidArgumentException('Unsupported NBT value type'),
        };
    }

    public function primitiveArray(string $name, int $type, array $values): self
    {
        $this->tagHeader(self::T_LIST, $name);
        $this->buf .= chr($type) . pack('N', count($values));

        $format = match ($type) {
            self::T_BYTE => 'c',
            self::T_SHORT => 'n',
            self::T_INT => 'N',
            default => throw new InvalidArgumentException('Bad primitive array type'),
        };

        foreach ($values as $v) {
            $this->buf .= pack($format, $v);
        }

        return $this;
    }

    public function compound(string $name, callable $fn): self
    {
        $this->tagHeader(self::T_COMPOUND, $name);
        $fn($this);
        $this->buf .= chr(self::T_END);

        return $this;
    }

    /** @param array<int, callable> $entries */
    public function listCompound(string $name, array $entries): self
    {
        $this->tagHeader(self::T_LIST, $name);
        $this->buf .= chr(self::T_COMPOUND) . pack('N', count($entries));

        foreach ($entries as $entry) {
            // В NBT элементы списка типа Compound НЕ имеют тега 0x0A и имени!
            // Они состоят только из своих полей (которые добавляет callback)
            // и завершающего TAG_End (0x00).
            $entry($this);
            $this->buf .= chr(self::T_END);
        }

        return $this;
    }

    public function byte(string $name, int $value): self
    {
        $this->tagHeader(self::T_BYTE, $name);
        $this->buf .= pack('c', $value);

        return $this;
    }

    public function short(string $name, int $value): self
    {
        $this->tagHeader(self::T_SHORT, $name);
        $this->buf .= pack('n', $value & 0xFFFF);

        return $this;
    }

    public function int(string $name, int $value): self
    {
        $this->tagHeader(self::T_INT, $name);
        $this->buf .= pack('N', $value & 0xFFFFFFFF);

        return $this;
    }

    public function long(string $name, int $value): self
    {
        $this->tagHeader(self::T_LONG, $name);
        $this->buf .= pack('J', $value);

        return $this;
    }

    public function float(string $name, float $value): self
    {
        $this->tagHeader(self::T_FLOAT, $name);
        $this->buf .= pack('G', $value);

        return $this;
    }

    public function double(string $name, float $value): self
    {
        $this->tagHeader(self::T_DOUBLE, $name);
        $this->buf .= pack('E', $value);

        return $this;
    }

    public function string(string $name, string $value): self
    {
        $this->tagHeader(self::T_STRING, $name);
        $this->buf .= pack('n', strlen($value)) . $value;

        return $this;
    }

    /** Вставляет уже готовый body корневого компаунда (без type+имени). */
    public function rawCompoundBody(string $rootNbt): self
    {
        // rootNbt = [type=10][len_hi][len_lo]... => тело начинается с байта 3.
        $this->buf .= substr($rootNbt, 3);

        return $this;
    }

    /**
     * Network NBT (1.13+): корневой тег Compound БЕЗ имени.
     * Формат: [Type=0x0A][Payload][Type=0x00 (End)]
     */
    public static function networkRoot(callable $fn): string
    {
        $nbt = new self();
        $nbt->buf .= chr(self::T_COMPOUND); // Только тип, БЕЗ имени
        $fn($nbt);
        $nbt->buf .= chr(self::T_END);
        return $nbt->buf;
    }

    public function longArray(string $name, array $values): self
    {
        $this->tagHeader(self::T_LONG_ARRAY, $name);
        $this->buf .= pack('N', count($values));
        foreach ($values as $v) {
            $this->buf .= pack('J', $v);
        }
        return $this;
    }

    private function tagHeader(int $type, string $name): void
    {
        $this->buf .= chr($type) . pack('n', strlen($name)) . $name;
    }
}

// ============================================================================
// Реестры 1.20.1 (Flyweight: сериализуются один раз, общие для всех клиентов)
// ============================================================================

final class RegistryData
{
    /**
     * Биомы Protocol 763 (id 0..63) — точный список vanilla 1.20/1.20.1
     * (проверен по minecraft-data data/pc/1.20/biomes.json).
     *
     * @var array<int, array{name: string, precipitation: bool, temperature: float, downfall: float}>
     */
    private const BIOMES = [
        ['name' => 'badlands', 'precipitation' => false, 'temperature' => 2.0, 'downfall' => 0.0],
        ['name' => 'bamboo_jungle', 'precipitation' => true, 'temperature' => 0.95, 'downfall' => 0.9],
        ['name' => 'basalt_deltas', 'precipitation' => false, 'temperature' => 2.0, 'downfall' => 0.0],
        ['name' => 'beach', 'precipitation' => true, 'temperature' => 0.8, 'downfall' => 0.4],
        ['name' => 'birch_forest', 'precipitation' => true, 'temperature' => 0.6, 'downfall' => 0.6],
        ['name' => 'cherry_grove', 'precipitation' => true, 'temperature' => 0.5, 'downfall' => 0.7],
        ['name' => 'cold_ocean', 'precipitation' => true, 'temperature' => 0.5, 'downfall' => 0.5],
        ['name' => 'crimson_forest', 'precipitation' => false, 'temperature' => 2.0, 'downfall' => 0.0],
        ['name' => 'deep_cold_ocean', 'precipitation' => true, 'temperature' => 0.5, 'downfall' => 0.5],
        ['name' => 'deep_dark', 'precipitation' => true, 'temperature' => 0.8, 'downfall' => 0.4],
        ['name' => 'deep_frozen_ocean', 'precipitation' => true, 'temperature' => 0.5, 'downfall' => 0.5],
        ['name' => 'deep_lukewarm_ocean', 'precipitation' => true, 'temperature' => 0.5, 'downfall' => 0.5],
        ['name' => 'deep_ocean', 'precipitation' => true, 'temperature' => 0.5, 'downfall' => 0.5],
        ['name' => 'desert', 'precipitation' => false, 'temperature' => 2.0, 'downfall' => 0.0],
        ['name' => 'dripstone_caves', 'precipitation' => true, 'temperature' => 0.8, 'downfall' => 0.4],
        ['name' => 'end_barrens', 'precipitation' => false, 'temperature' => 0.5, 'downfall' => 0.0],
        ['name' => 'end_highlands', 'precipitation' => false, 'temperature' => 0.5, 'downfall' => 0.0],
        ['name' => 'end_midlands', 'precipitation' => false, 'temperature' => 0.5, 'downfall' => 0.0],
        ['name' => 'eroded_badlands', 'precipitation' => false, 'temperature' => 2.0, 'downfall' => 0.0],
        ['name' => 'flower_forest', 'precipitation' => true, 'temperature' => 0.7, 'downfall' => 0.8],
        ['name' => 'forest', 'precipitation' => true, 'temperature' => 0.7, 'downfall' => 0.8],
        ['name' => 'frozen_ocean', 'precipitation' => true, 'temperature' => 0.0, 'downfall' => 0.5],
        ['name' => 'frozen_peaks', 'precipitation' => true, 'temperature' => -0.7, 'downfall' => 0.9],
        ['name' => 'frozen_river', 'precipitation' => true, 'temperature' => 0.0, 'downfall' => 0.5],
        ['name' => 'grove', 'precipitation' => true, 'temperature' => -0.2, 'downfall' => 0.8],
        ['name' => 'warped_forest', 'precipitation' => false, 'temperature' => 2.0, 'downfall' => 0.0],
        ['name' => 'jagged_peaks', 'precipitation' => true, 'temperature' => -0.7, 'downfall' => 0.9],
        ['name' => 'jungle', 'precipitation' => true, 'temperature' => 0.95, 'downfall' => 0.9],
        ['name' => 'lukewarm_ocean', 'precipitation' => true, 'temperature' => 0.5, 'downfall' => 0.5],
        ['name' => 'lush_caves', 'precipitation' => true, 'temperature' => 0.9, 'downfall' => 0.9],
        ['name' => 'mangrove_swamp', 'precipitation' => true, 'temperature' => 0.8, 'downfall' => 0.9],
        ['name' => 'meadow', 'precipitation' => true, 'temperature' => 0.5, 'downfall' => 0.7],
        ['name' => 'mushroom_fields', 'precipitation' => true, 'temperature' => 0.9, 'downfall' => 1.0],
        ['name' => 'nether_wastes', 'precipitation' => false, 'temperature' => 2.0, 'downfall' => 0.0],
        ['name' => 'ocean', 'precipitation' => true, 'temperature' => 0.5, 'downfall' => 0.5],
        ['name' => 'plains', 'precipitation' => true, 'temperature' => 0.8, 'downfall' => 0.4],
        ['name' => 'river', 'precipitation' => true, 'temperature' => 0.5, 'downfall' => 0.5],
        ['name' => 'savanna', 'precipitation' => false, 'temperature' => 1.2, 'downfall' => 0.0],
        ['name' => 'savanna_plateau', 'precipitation' => false, 'temperature' => 1.0, 'downfall' => 0.0],
        ['name' => 'small_end_islands', 'precipitation' => false, 'temperature' => 0.5, 'downfall' => 0.0],
        ['name' => 'snowy_beach', 'precipitation' => true, 'temperature' => 0.05, 'downfall' => 0.3],
        ['name' => 'snowy_plains', 'precipitation' => true, 'temperature' => 0.0, 'downfall' => 0.5],
        ['name' => 'snowy_slopes', 'precipitation' => true, 'temperature' => -0.3, 'downfall' => 0.9],
        ['name' => 'snowy_taiga', 'precipitation' => true, 'temperature' => -0.5, 'downfall' => 0.4],
        ['name' => 'soul_sand_valley', 'precipitation' => false, 'temperature' => 2.0, 'downfall' => 0.0],
        ['name' => 'stone_shore', 'precipitation' => true, 'temperature' => 0.2, 'downfall' => 0.3],
        ['name' => 'sunflower_plains', 'precipitation' => true, 'temperature' => 0.8, 'downfall' => 0.4],
        ['name' => 'swamp', 'precipitation' => true, 'temperature' => 0.8, 'downfall' => 0.9],
        ['name' => 'taiga', 'precipitation' => true, 'temperature' => 0.25, 'downfall' => 0.8],
        ['name' => 'the_end', 'precipitation' => false, 'temperature' => 0.5, 'downfall' => 0.0],
        ['name' => 'the_void', 'precipitation' => false, 'temperature' => 0.5, 'downfall' => 0.5],
        ['name' => 'warm_ocean', 'precipitation' => true, 'temperature' => 0.5, 'downfall' => 0.5],
        ['name' => 'windswept_forest', 'precipitation' => true, 'temperature' => 0.2, 'downfall' => 0.3],
        ['name' => 'windswept_gravelly_hills', 'precipitation' => true, 'temperature' => 0.2, 'downfall' => 0.3],
        ['name' => 'windswept_hills', 'precipitation' => true, 'temperature' => 0.2, 'downfall' => 0.3],
        ['name' => 'windswept_jungle', 'precipitation' => true, 'temperature' => 0.95, 'downfall' => 0.9],
        ['name' => 'windswept_savanna', 'precipitation' => false, 'temperature' => 1.2, 'downfall' => 0.0],
        ['name' => 'wooded_badlands', 'precipitation' => false, 'temperature' => 2.0, 'downfall' => 0.0],
        ['name' => 'old_growth_spruce_taiga', 'precipitation' => true, 'temperature' => 0.25, 'downfall' => 0.8],
        ['name' => 'old_growth_pine_taiga', 'precipitation' => true, 'temperature' => 0.3, 'downfall' => 0.8],
        ['name' => 'old_growth_birch_forest', 'precipitation' => true, 'temperature' => 0.6, 'downfall' => 0.6],
        ['name' => 'sparse_jungle', 'precipitation' => true, 'temperature' => 0.95, 'downfall' => 0.8],
        ['name' => 'ice_spikes', 'precipitation' => true, 'temperature' => 0.0, 'downfall' => 0.5],
    ];

    /** @var string[] Обязательные реестры, которые клиент 1.20.1 читает при входе. */
    private const REQUIRED_REGISTRIES = [
        'minecraft:dimension_type' => self::class . '::dimensionEntries',
        'minecraft:worldgen/biome' => self::class . '::biomeEntries',
    ];

    private static ?string $registryPayload = null;
    private static ?string $dimensionCodec = null;

    /** @return array<int, callable> По одной callback на запись реестра. */
    public static function dimensionEntries(): array
    {
        return [
            static function (Nbt $e): void {
                // Структура элемента списка реестра: id, name, element
                $e->int('id', 0)
                    ->string('name', 'minecraft:overworld')
                    ->compound('element', function (Nbt $n): void {
                        $n->byte('ultrawarm', 0)
                            ->byte('natural', 1)
                            ->double('coordinate_scale', 1.0)
                            ->float('ambient_light', 0.0)
                            ->byte('has_ceiling', 0)
                            ->byte('has_skylight', 1)
                            ->byte('piglin_safe', 0)
                            ->byte('bed_works', 1)
                            ->byte('respawn_anchor_works', 0)
                            ->byte('has_raids', 1)
                            ->int('min_y', -64)
                            ->int('height', 384)
                            ->int('logical_height', 384)
                            ->string('infiniburn', '#minecraft:infiniburn_overworld')
                            ->string('effects', 'minecraft:overworld')
                            ->compound('monster_spawn_light_level', function (Nbt $n): void {
                                $n->string('type', 'minecraft:constant')
                                    ->int('value', 0);
                            })
                            ->int('monster_spawn_block_light_limit', 0);
                    });
            },
        ];
    }

    /** @return array<int, callable> */
    public static function biomeEntries(): array
    {
        $callbacks = [];
        foreach (self::BIOMES as $id => $biome) {
            $callbacks[] = self::biomeEntryCallback($id, $biome);
        }

        return $callbacks;
    }

    /** Growable-колбэк добавления одного биома в список записей реестра. */
    public static function biomeEntryCallback(int $id, array $biome): callable
    {
        $name = $biome['name'];
        $precip = $biome['precipitation'] ? 1 : 0;
        $temp = $biome['temperature'];
        $down = $biome['downfall'];

        return static function (Nbt $n) use ($id, $name, $precip, $temp, $down): void {
            // Структура элемента списка реестра: id, name, element
            $n->int('id', $id)
                ->string('name', 'minecraft:' . $name)
                ->compound('element', function (Nbt $e) use ($precip, $temp, $down): void {
                    $e->byte('has_precipitation', $precip)
                        ->float('temperature', $temp)
                        ->float('downfall', $down)
                        ->compound('effects', function (Nbt $fx): void {
                            $fx->int('sky_color', 0x7A7FF)
                                ->int('fog_color', 0xC0D7F)
                                ->int('water_color', 0x3F76E4)
                                ->int('water_fog_color', 0x50533)
                                ->compound('mood_sound', function (Nbt $ms): void {
                                    $ms->string('sound', 'minecraft:ambient.cave')
                                        ->int('tick_delay', 6000)
                                        ->int('block_search_extent', 8)
                                        ->double('offset', 2.0);
                                });
                        });
                });
        };
    }

    /** Корневой NBT одной записи dimension_type (overworld, 1.20.1). */
    public static function overworldDimension(): string
    {
        static $cached = null;

        return $cached ??= Nbt::networkRoot(function (Nbt $n): void {
            $n->compound('element', function (Nbt $e): void {
                $e->byte('ultrawarm', 0)
                    ->byte('natural', 1)
                    ->double('coordinate_scale', 1.0)
                    ->double('ambient_light', 0.0)
                    ->byte('has_ceiling', 0)
                    ->byte('has_skylight', 1)
                    ->byte('piglin_safe', 0)
                    ->byte('bed_works', 1)
                    ->byte('respawn_anchor_works', 0)
                    ->byte('has_raids', 1)
                    ->int('min_y', -64)
                    ->int('height', 384)
                    ->int('logical_height', 384)
                    ->string('infiniburn', '#minecraft:infiniburn_overworld')
                    ->string('effects', 'minecraft:overworld')
                    ->int('monster_spawn_light_level', 0)
                    ->int('monster_spawn_block_light_limit', 0);
            })
                ->int('id', 0)
                ->string('name', 'minecraft:overworld');
        });
    }

    /**
     * Payload пакета Sync Registries (play S2C 0x39 в 1.20.1 отсутствует —
     * реестры идут целиком в dimensionCodec пакета Join Game, поэтому здесь
     * собирается именно codec). Возвращает кэш (Flyweight).
     */
    public static function dimensionCodec(): string
    {
        if (self::$dimensionCodec !== null) {
            return self::$dimensionCodec;
        }

        self::$dimensionCodec = Nbt::networkRoot(function (Nbt $n): void {
            // Реестр измерений
            $n->compound('minecraft:dimension_type', function (Nbt $reg): void {
                $reg->string('type', 'minecraft:dimension_type')
                    ->listCompound('value', self::dimensionEntries());
            });

            // Реестр биомов
            $n->compound('minecraft:worldgen/biome', function (Nbt $reg): void {
                $reg->string('type', 'minecraft:worldgen/biome')
                    ->listCompound('value', self::biomeEntries());
            });
        });

        return self::$dimensionCodec;
    }

    private function __construct()
    {
    }
}

// ============================================================================
// Генерация мира (плоский мир y=-64..319)
// ============================================================================

final class WorldGenerator
{
    public const SPAWN_X = 0.5;
    public const SPAWN_Y = -63.0;
    public const SPAWN_Z = 0.5;

    /** Радиус чанков вокруг спавна, которые генерируются лениво (memory-safe). */
    public const CHUNK_RADIUS = 4;

    /** @return array{name: string, spawn: array{x: float, y: float, z: float}} */
    public static function generateFlatWorld(): array
    {
        return [
            'name' => 'minecraft:overworld',
            'spawn' => ['x' => self::SPAWN_X, 'y' => self::SPAWN_Y, 'z' => self::SPAWN_Z],
        ];
    }
}

// ============================================================================
// Фабрика пакетов (Facade)
// ============================================================================

final class PacketFactory
{
    /** @var int Монотонный идентификатор телепорта. */
    private int $teleportIdCounter = 0;

    /** @var array<string, string> Flyweight-кэш сериализованных чанков. */
    private array $chunkCache = [];

    public function __construct(private readonly ServerConfig $config)
    {
    }

    public function createStatusResponse(callable $onlineCountProvider): string
    {
        $status = [
            'version' => [
                'name' => '1.20.1',
                'protocol' => $this->config->protocolVersion,
            ],
            'players' => [
                'max' => $this->config->maxPlayers,
                'online' => $onlineCountProvider(),
                'sample' => [],
            ],
            'description' => [
                'text' => $this->config->motd,
            ],
            'enforcesSecureChat' => false,
        ];

        return StringHandler::write((string) json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** Login Success (login S2C 0x02): UUID + Username + Properties(empty). */
    public function createLoginSuccess(string $uuid, string $username): string
    {
        return Uuid::toBinary($uuid) . StringHandler::write($username) . VarInt::write(0);
    }

    /** Login Disconnect (login S2C 0x00): reason = JSON text component. */
    public function createLoginDisconnect(string $reason): string
    {
        return StringHandler::write((string) json_encode(['text' => $reason], JSON_UNESCAPED_UNICODE));
    }

    /**
     * Join Game (play S2C 0x29, protocol 763).
     *
     * Порядок полей строго по packet_login из minecraft-data 1.20:
     * entityId(i32) isHardcore(bool) gameMode(u8) previousGameMode(i8)
     * worldNames(array<string>) dimensionCodec(nbt) worldType(string)
     * worldName(string) hashedSeed(i64) maxPlayers(varint) viewDistance(varint)
     * simulationDistance(varint) reducedDebugInfo(bool) enableRespawnScreen(bool)
     * isDebug(bool) isFlat(bool) death(option) portalCooldown(varint)
     */
    public function createJoinGame(int $entityId): string
    {
        $data = pack('N', $entityId & 0xFFFFFFFF);   // entityId (i32, big-endian)
        $data .= chr(0);                              // isHardcore = false
        $data .= chr(1);                              // gameMode u8: 1 = Creative
        $data .= pack('c', -1);                       // previousGameMode = none
        $data .= VarInt::write(1) . StringHandler::write('minecraft:overworld'); // worldNames
        $data .= RegistryData::dimensionCodec();      // dimensionCodec (NBT)
        $data .= StringHandler::write('minecraft:overworld'); // worldType
        $data .= StringHandler::write('minecraft:overworld'); // worldName
        $data .= pack('J', 0);                        // hashedSeed
        $data .= VarInt::write($this->config->maxPlayers);
        $data .= VarInt::write($this->config->viewDistance);
        $data .= VarInt::write($this->config->simulationDistance);
        $data .= chr(0);                              // reducedDebugInfo
        $data .= chr(1);                              // enableRespawnScreen
        $data .= chr(0);                              // isDebug
        $data .= chr(0);                              // isFlat
        $data .= chr(0);                              // death location: absent
        $data .= VarInt::write(0);                    // portalCooldown

        return $data;
    }

    /** Player Info (play S2C 0x3A): action bitflags=0x01 (add_player), 1 игрок. */
    public function createPlayerInfoAdd(string $uuid, string $username): string
    {
        return chr(0x01)                             // actions bitflags: add_player
            . VarInt::write(1)                       // количество игроков
            . Uuid::toBinary($uuid)
            . StringHandler::write($username)        // name (game_profile.displayName)
            . VarInt::write(0)                       // properties: 0
            . chr(0)                                 // chat session unsupported (bool)
            . VarInt::write(1)                       // gamemode: creative
            . VarInt::write(0)                       // ping
            . chr(0);                                // display name: absent
    }

    /** Player Remove (play S2C 0x39): VarInt count + UUID[]. */
    public function createPlayerInfoRemove(string $uuid): string
    {
        return VarInt::write(1) . Uuid::toBinary($uuid);
    }

    /** Declare Commands (play S2C 0x10): nodes=[], rootIndex=0. */
    public function createDeclareCommands(): string
    {
        return VarInt::write(0) . VarInt::write(0);
    }

    /** Game Event (play S2C 0x1F): reason(u8) + value(f32). 13 = start waiting for chunks. */
    public function createGameEvent(int $type, float $value): string
    {
        return chr($type) . pack('G', $value);
    }

    /** Held Item Slot (play S2C 0x4D): slot i8. */
    public function createHeldItemChange(): string
    {
        return pack('c', 0);
    }

    /** Experience (play S2C 0x56): bar f32 + level varint + total varint. */
    public function createSetExperience(): string
    {
        return pack('G', 0.0) . VarInt::write(0) . VarInt::write(0);
    }

    /** Update Health (play S2C 0x57): health f32 + food varint + saturation f32. */
    public function createUpdateHealth(): string
    {
        return pack('G', 20.0) . VarInt::write(20) . pack('G', 5.0);
    }

    /** Hurt Armor (play S2C 0x21): entity varint + yaw f32. */
    public function createHurtArmor(int $entityId): string
    {
        return VarInt::write($entityId) . pack('G', 0.0);
    }

    /** Set Cooldown (play S2C 0x15): item varint + ticks varint. */
    public function createSetCooldown(): string
    {
        return VarInt::write(0) . VarInt::write(0);
    }

    /** Open Window (inventory): window id/type/title. */
    public function createInitializeWindow(): string
    {
        return chr(0) . VarInt::write(0) . StringHandler::write('');
    }

    /** Chunk Data (play S2C 0x24 map_chunk, protocol 763) — плоский бедрок-мир. */
    public function createChunkData(int $chunkX, int $chunkZ): string
    {
        $cacheKey = $chunkX . ':' . $chunkZ;
        if (isset($this->chunkCache[$cacheKey])) {
            return $this->chunkCache[$cacheKey];
        }

        // Ограничиваем размер кэша: вытесняем самый старый чанк (LRU-lite).
        if (count($this->chunkCache) > 1024) {
            array_shift($this->chunkCache);
        }

        $data = pack('NN', $chunkX, $chunkZ);

        // Heightmaps NBT: motion_blocking + ocean_floor (short arrays по 17 нулей)
        $hm = Nbt::networkRoot(function (Nbt $n): void {
            $zeros = array_fill(0, 256, 0);
            $n->longArray('MOTION_BLOCKING', $zeros)
                ->longArray('OCEAN_FLOOR', $zeros);
        });
        $data .= $hm;

        // chunkData (varint length-prefixed buffer): 24 секции (y=-64..320)
        $sections = '';
        // Секции 0..3: y=-64..0 — заполнены (бедрок y=-64, камень выше)
        // Секция 4: y=0..16 — воздух над бедроком? Спавн на y=-60 → секция 0.
        for ($s = 0; $s < 24; $s++) {
            $blockCount = pack('n', 0);
            if ($s === 0) {
                // Бедрок на нижнем уровне секции 0: non-air block count
                $blockCount = pack('n', 256);
                // Палитра: air(0), bedrock(31) → bits per entry = 4 (min 4)
                $palette = VarInt::write(2) . VarInt::write(0) . VarInt::write(31);
                // 4 бита * 4096 блоков / 64 = 256 long
                $longs = 256;
                // Заполняем: все блоки уровня y=0 внутри секции (первые 256 индексов) = bedrock (palette index 1)
                $packed = str_repeat("\x00", $longs * 8);
                // Индексы 0..255 (нижний слой 16x16) получают значение 1 (bedrock)
                $words = array_fill(0, $longs, 0);
                for ($i = 0; $i < 256; $i++) {
                    $wordIdx = intdiv($i * 4, 64);
                    $bitOff = ($i * 4) % 64;
                    $words[$wordIdx] |= (1 << $bitOff);
                }
                $packed = '';
                foreach ($words as $w) {
                    $packed .= pack('J', $w);
                }
                $sections .= $blockCount . chr(4) . $palette . VarInt::write($longs) . $packed;
            } else {
                // Полностью воздушная секция: single-value palette air, 0 longs
                $sections .= $blockCount . chr(0) . VarInt::write(1) . VarInt::write(0) . VarInt::write(0);
            }
        }

        $data .= VarInt::write(strlen($sections)) . $sections;

        // Block entities count
        $data .= VarInt::write(0);

        // Light masks: skyLightMask=[1...] но проще: все секции пусты по свету →
        // sky mask = 0b1111... (24 секции) — корректнее дать empty-маски.
        $maskAll = pack('J', 0xFFFFFF); // 24 бита = 24 секции
        $data .= VarInt::write(1) . $maskAll;          // skyLightMask
        $data .= VarInt::write(1) . $maskAll;          // blockLightMask
        $data .= VarInt::write(1) . $maskAll;          // emptySkyLightMask
        $data .= VarInt::write(1) . $maskAll;          // emptyBlockLightMask
        // skyLight array: 24 записи по 2048 байт (все нули = темнота до обновления skylight)
        $data .= VarInt::write(24);
        for ($i = 0; $i < 24; $i++) {
            $data .= VarInt::write(2048) . str_repeat("\x00", 2048);
        }
        // blockLight array: 24 записи
        $data .= VarInt::write(24);
        for ($i = 0; $i < 24; $i++) {
            $data .= VarInt::write(2048) . str_repeat("\x00", 2048);
        }

        return $this->chunkCache[$cacheKey] = $data;
    }

    /** Unload Chunk (play S2C 0x1E): chunkX(i32) chunkZ(i32). */
    public function createUnloadChunk(int $chunkX, int $chunkZ): string
    {
        return pack('NN', $chunkX, $chunkZ);
    }

    /** Spawn Position (play S2C 0x50): position(long) + angle f32. */
    public function createSpawnPosition(int $x, int $y, int $z): string
    {
        return pack('J', self::encodeBlockPosition($x, $y, $z)) . pack('G', 0.0);
    }

    /** Named Entity Spawn (0x03) не нужен; вместо него клиент сам рендерит себя. */

    /** Position and Teleport (play S2C 0x3C): x,y,z f64 + yaw,pitch f32 + flags i8 + teleportId varint. */
    public function createPlayerPosition(float $x, float $y, float $z): string
    {
        $teleportId = ++$this->teleportIdCounter;

        return pack('E', $x) . pack('E', $y) . pack('E', $z)
            . pack('G', 0.0)             // yaw
            . pack('G', 0.0)             // pitch
            . chr(0)                     // flags: абсолютная позиция
            . VarInt::write($teleportId);
    }

    /** Текущий ожидаемый teleport id (для подтверждения клиентом). */
    public function currentTeleportId(): int
    {
        return $this->teleportIdCounter;
    }

    /** Player Abilities (play S2C 0x34): flags i8 + flyingSpeed f32 + walkingSpeed f32. */
    public function createPlayerAbilities(): string
    {
        return chr(0x01)                 // bit0 invulnerable| mayfly... 0x01 invulnerable, 0x02 fly, 0x04 instant break, 0x08 no clip
            . pack('G', 0.05)
            . pack('G', 0.1);
    }

    /** Difficulty (play S2C 0x0C): difficulty u8 + locked bool. */
    public function createServerDifficulty(): string
    {
        return chr(0x01) . chr(0x00);    // easy, not locked
    }

    /** Simulation Distance (play S2C 0x5C): distance varint. */
    public function createSimulationDistance(int $distance): string
    {
        return VarInt::write($distance);
    }

    /** Update Time (play S2C 0x5E): age i64 + time i64. */
    public function createTimeUpdate(): string
    {
        return pack('J', 6000) . pack('J', 6000);
    }

    /** Keep Alive (play S2C 0x23): id i64. */
    public function createKeepAlive(): string
    {
        return pack('J', random_int(0, PHP_INT_MAX));
    }

    /** System Chat (play S2C 0x64): message string(JSON/text) + overlay bool. */
    public function createSystemChatMessage(string $text): string
    {
        return StringHandler::write((string) json_encode(['text' => $text], JSON_UNESCAPED_UNICODE)) . chr(0);
    }

    /** Action Bar (play S2C 0x46): text component string. */
    public function createActionBar(string $text): string
    {
        return StringHandler::write((string) json_encode(['text' => $text], JSON_UNESCAPED_UNICODE));
    }

    /** Custom Payload / Plugin Message (play S2C 0x17): channel + rest buffer. */
    /** Feature Flags (Configuration, Protocol 764): пустой список флагов. */
    public function createFeatureFlags(): string
    {
        return VarInt::write(0);
    }

    public function createPluginMessageBrand(): string
    {
        return StringHandler::write('MC|Brand') . StringHandler::write('vanilla');
    }

    /** Кодирование Block Position (протокол): ((x&0x3FFFFFF)<<38)|((z&0x3FFFFFF)<<12)|(y&0xFFF). */
    public static function encodeBlockPosition(int $x, int $y, int $z): int
    {
        return (($x & 0x3FFFFFF) << 38) | (($z & 0x3FFFFFF) << 12) | ($y & 0xFFF);
    }
}

// ============================================================================
// Менеджер соединений
// ============================================================================

final class ClientConnection
{
    public string $buffer = '';
    public ProtocolState $state = ProtocolState::Handshake;
    public string $username = '';
    public string $uuid = '';
    public int $lastActivity;
    public ?int $pendingKeepAliveId = null;
    public bool $chunksSent = false;
    /** @var string Неотправленный исходящий буфер (сокеты неблокирующие). */
    public string $outBuffer = '';
    /** @var int Позиция в очереди чанков на ленивую отправку. */
    public int $chunkQueueIndex = 0;
    /** @var int Протокол клиента из Handshake (763 = 1.20.1). */
    public int $protocol = 0;
    /** Состояние Login Plugin Request fallback: none|scheduled|sent|answered. */
    public string $pluginRequestState = 'none';
    /** Unix-time отправки Login Success (для 5-сек таймаута Login Acknowledged). */
    public ?int $loginSuccessAt = null;

    /** @param resource $socket */
    public function __construct(
        public readonly int $id,
        public readonly mixed $socket,
    ) {
        $this->lastActivity = time();
    }
}

final class ConnectionManager
{
    /** @var array<int, ClientConnection> */
    private array $connections = [];

    /** @var array<int, array{clientId:int, dueAt:float, packetId:int, data:string, label:string, guard:?callable}> */
    private array $delayedPackets = [];

    public function __construct(private readonly MinecraftServer $server)
    {
    }

    /**
     * Планирует отправку пакета через $delay секунд (одноразово).
     * $guard — опциональный callback(ClientConnection): bool; если возвращает
     * false, пакет не отправляется (например, состояние уже сменилось).
     */
    public function scheduleDelayedPacket(
        int $clientId,
        float $delay,
        int $packetId,
        string $data,
        string $label = '',
        ?callable $guard = null,
    ): void {
        $this->delayedPackets[] = [
            'clientId' => $clientId,
            'dueAt' => microtime(true) + $delay,
            'packetId' => $packetId,
            'data' => $data,
            'label' => $label,
            'guard' => $guard,
        ];
        PacketLogger::logInfo("🕒 Отложенный пакет '$label' (0x" . dechex($packetId) . ") для клиента $clientId через {$delay}s");
    }

    /** Тик отложенных пакетов — вызывается из главного цикла сервера. */
    public function tickDelayedPackets(): void
    {
        if ($this->delayedPackets === []) {
            return;
        }

        $now = microtime(true);
        foreach ($this->delayedPackets as $key => $job) {
            if ($now < $job['dueAt']) {
                continue;
            }
            unset($this->delayedPackets[$key]);

            $connection = $this->connections[$job['clientId']] ?? null;
            if ($connection === null) {
                continue;
            }
            if ($job['guard'] !== null && !$job['guard']($connection)) {
                PacketLogger::logInfo("🚫 Отменён отложенный пакет '{$job['label']}' для клиента {$job['clientId']} (условие не выполнено)");
                if ($job['packetId'] === PacketId::CB_LOGIN_PLUGIN_REQUEST) {
                    $connection->pluginRequestState = 'cancelled';
                }
                continue;
            }

            PacketLogger::logInfo("📨 Отправка отложенного пакета '{$job['label']}' клиенту {$job['clientId']}");
            $this->sendPacket($job['clientId'], $job['packetId'], $job['data']);
            if ($job['packetId'] === PacketId::CB_LOGIN_PLUGIN_REQUEST) {
                $connection->pluginRequestState = 'sent';
            }
        }
    }

    /** @param resource|false $socket */
    public function acceptNewConnection(mixed $socket): void
    {
        if ($socket === false) {
            return;
        }

        stream_set_blocking($socket, false);
        stream_set_timeout($socket, 30);

        $connection = new ClientConnection((int) $socket, $socket);
        $this->connections[$connection->id] = $connection;

        PacketLogger::logConnection("Новое подключение (ID: {$connection->id})");
    }

    /**
     * Надёжное неблокирующее чтение всех соединений (Windows-safe).
     *
     * Алгоритм:
     *  1. stream_select() с нулевым таймаутом — мгновенный опрос ядра ОС,
     *     какие сокеты готовы к чтению (не зависит от особенностей fread на
     *     Windows, где неполные/пустые чтения случаются чаще, чем на Linux).
     *  2. Для каждого готового сокета вычитываем ДРАЙНОМ по 8192 байта до
     *     упора в EAGAIN/EOF — гарантированно забираем весь кадр
     *     Login Acknowledged (0x03), даже если он пришёл фрагментами.
     *  3. Буфер обрабатывается КАЖДЫЙ тик для каждого соединения, даже если
     *     новых данных не пришло (там мог остаться незавершённый кадр).
     *  4. feof() проверяется только ПОСЛЕ обработки буфера.
     *  5. Таймаут-защита: если клиент в Login получил Login Success, но за
     *     5 секунд не прислал Login Acknowledged (0x03) — соединение
     *     закрывается с явным логом (типичный признак premium-аккаунта на
     *     offline-сервере: клиент ждёт Encryption Request, которого нет).
     */
    public function processConnections(): void
    {
        $now = time();

        // --- 1. Собираем активные сокеты для stream_select ---
        $readSockets = [];
        /** @var array<int, int> $socketToClient маппинг (int)socket → clientId */
        $socketToClient = [];

        foreach ($this->connections as $clientId => $connection) {
            if ($now - $connection->lastActivity > 120) {
                PacketLogger::logConnection("Таймаут соединения для клиента $clientId");
                $this->closeConnection($clientId);
                continue;
            }

            if (is_resource($connection->socket)) {
                $key = (int) $connection->socket;
                $readSockets[$key] = $connection->socket;
                $socketToClient[$key] = $clientId;
            } else {
                PacketLogger::logConnection("Соединение разорвано (invalid resource) для клиента $clientId");
                $this->closeConnection($clientId);
            }
        }

        // --- 2. Неблокирующий опрос готовности к чтению (таймаут 0) ---
        if ($readSockets !== []) {
            $write = null;
            $except = null;
            $ready = @stream_select($readSockets, $write, $except, 0);

            if ($ready === false) {
                // Ошибка select — пропускаем тик, но буферы всё равно
                // обработаем ниже (шаг 3 выполняется каждый тик).
                PacketLogger::logDebug('⚠️ stream_select вернул ошибку, пропуск опроса чтения');
            } else {
                foreach ($readSockets as $key => $socketResource) {
                    $clientId = $socketToClient[$key] ?? null;
                    $connection = $this->connections[$clientId] ?? null;

                    if ($connection === null || !is_resource($socketResource)) {
                        continue;
                    }

                    // Вычитываем ВСЁ доступное (drain-цикл, 8KB за порцию).
                    $totalRead = '';
                    while (true) {
                        $data = @fread($socketResource, 8192);

                        if ($data === false || $data === '') {
                            break; // EAGAIN (нет данных) или EOF
                        }

                        $totalRead .= $data;

                        if (strlen($data) < 8192) {
                            break; // прочитан весь доступный буфер ОС
                        }
                    }

                    if ($totalRead !== '') {
                        $connection->buffer .= $totalRead;
                        $connection->lastActivity = $now;

                        PacketLogger::logDebug(sprintf(
                            '📥 READ | Клиент %d | State: %s | +%d байт | raw=%s',
                            $clientId,
                            $connection->state->value,
                            strlen($totalRead),
                            substr(bin2hex($totalRead), 0, 60)
                        ));
                    } elseif (feof($socketResource)) {
                        // Реальный EOF, а не просто отсутствие данных:
                        // перед закрытием обработаем остаток входящего
                        // буфера — там мог лежать кадр Login Acknowledged.
                        if ($connection->buffer !== '') {
                            $this->server->processBuffer($clientId, $connection);
                        }
                        $this->debugConnectionState($connection);
                        PacketLogger::logConnection("Соединение разорвано (EOF) для клиента $clientId");
                        $this->closeConnection($clientId);
                    }
                }
            }
        }

        // --- 3. Обрабатываем буфер КАЖДЫЙ тик для каждого соединения ---
        // Даже если новых данных не пришло: в буфере мог остаться кадр,
        // собранный из нескольких фрагментов предыдущих чтений.
        foreach ($this->connections as $clientId => $connection) {
            if (!isset($this->connections[$clientId])) {
                continue; // соединение закрылось на шаге 2 этого же тика
            }

            if ($connection->buffer !== '') {
                $this->server->processBuffer($clientId, $connection);
            }

            // Хвост в буфере после обработки — клиент прислал данные, которые
            // не разбираются как валидный пакет состояния Login.
            if ($connection->state === ProtocolState::Login
                && $connection->username !== ''
                && $connection->buffer !== ''
                && $connection->loginSuccessAt !== null
                && ($now - $connection->loginSuccessAt) > 1
            ) {
                PacketLogger::logInfo(
                    '⚠️ Клиент ' . $clientId . ' не прислал корректный Login Acknowledged (0x03); '
                    . 'хвост буфера: ' . strlen($connection->buffer) . ' байт ('
                    . bin2hex(substr($connection->buffer, 0, 32)) . ')'
                );
            }
        }
    }

    /**
     * Отладка состояния соединения: ресурс сокета, EOF, таймаут,
     * unread_bytes, размеры входного/выходного буферов.
     */
    public function debugConnectionState(ClientConnection $connection): void
    {
        $socket = $connection->socket;
        $valid = is_resource($socket);
        $meta = $valid ? @stream_get_meta_data($socket) : [];

        PacketLogger::logInfo('🔍 Connection state debug:');
        PacketLogger::logInfo('  Client ID: ' . $connection->id);
        PacketLogger::logInfo('  State: ' . $connection->state->value);
        PacketLogger::logInfo('  Socket resource: ' . ($valid ? 'valid' : 'invalid'));
        PacketLogger::logInfo('  Socket EOF: ' . ($valid && feof($socket) ? 'true' : 'false'));
        PacketLogger::logInfo('  Socket timed_out: ' . (($meta['timed_out'] ?? false) ? 'true' : 'false'));
        PacketLogger::logInfo('  Socket unread_bytes: ' . ($meta['unread_bytes'] ?? '?'));
        PacketLogger::logInfo('  In-buffer: ' . strlen($connection->buffer) . ' bytes');
        PacketLogger::logInfo('  Out-buffer: ' . strlen($connection->outBuffer) . ' bytes');
    }

    public function closeConnection(int $clientId): void
    {
        if (!isset($this->connections[$clientId])) {
            return;
        }

        $connection = $this->connections[$clientId];

        if (is_resource($connection->socket)) {
            @fclose($connection->socket);
        }

        unset($this->connections[$clientId]);
        PacketLogger::logConnection("Клиент $clientId отключен");
    }

    public function sendPacket(int $clientId, int $packetId, string $data): bool
    {
        $connection = $this->connections[$clientId] ?? null;

        if ($connection === null || !is_resource($connection->socket) || feof($connection->socket)) {
            return false;
        }

        $payload = VarInt::write($packetId) . $data;
        $frame = VarInt::write(strlen($payload)) . $payload;

        // Детальное логирование каждого исходящего пакета: ID (hex), длина,
        // raw bytes (первые 50 байт).
        PacketLogger::logPacket('⬅️ SENT', $clientId, $packetId, $frame);
        $rawHex = substr(bin2hex($frame), 0, 100);
        PacketLogger::logInfo(sprintf(
            "  ⬇️ Raw[%d]: %s%s",
            strlen($frame),
            $rawHex,
            strlen($frame) > 50 ? '…' : ''
        ));

        // Сокет неблокирующий: при переполнении send-буфера fwrite пишет
        // частично. Остаток дописываем в outBuffer и флашим каждый тик цикла.
        $toSend = $connection->outBuffer . $frame;
        $written = @fwrite($connection->socket, $toSend);

        if ($written === false) {
            PacketLogger::logError(
                'Ошибка отправки пакета 0x' . dechex($packetId) . " клиенту $clientId"
            );
            $this->closeConnection($clientId);

            return false;
        }

        $connection->outBuffer = $written >= strlen($toSend) ? '' : substr($toSend, $written);
        $connection->lastActivity = time();

        return true;
    }

    /** Флаш исходящих буферов всех соединений (вызывается из главного цикла). */
    public function flushOutBuffers(): void
    {
        foreach ($this->connections as $clientId => $connection) {
            if ($connection->outBuffer === '') {
                continue;
            }

            if (!is_resource($connection->socket) || feof($connection->socket)) {
                $this->closeConnection($clientId);
                continue;
            }

            $written = @fwrite($connection->socket, $connection->outBuffer);

            if ($written === false) {
                $this->closeConnection($clientId);
                continue;
            }

            $connection->outBuffer = $written >= strlen($connection->outBuffer)
                ? ''
                : substr($connection->outBuffer, $written);
        }
    }

    public function isClientConnected(int $clientId): bool
    {
        $connection = $this->connections[$clientId] ?? null;

        return $connection !== null
            && is_resource($connection->socket)
            && !feof($connection->socket);
    }

    public function getConnection(int $clientId): ?ClientConnection
    {
        return $this->connections[$clientId] ?? null;
    }

    /** @return array<int, ClientConnection> */
    public function getConnections(): array
    {
        return $this->connections;
    }

    public function getConnectionCount(): int
    {
        return count($this->connections);
    }
}

// ============================================================================
// Обработчики состояний (State + Chain of Responsibility)
// ============================================================================

interface PacketHandler
{
    public function handle(ClientConnection $connection, string $packetData): void;
}

final class HandshakeHandler implements PacketHandler
{
    public function handle(ClientConnection $connection, string $packetData): void
    {
        $offset = 0;
        $packetId = VarInt::read($packetData, $offset);

        if ($packetId !== 0x00) {
            return;
        }

        $protocolVersion = VarInt::read($packetData, $offset);
        $serverAddress = StringHandler::read($packetData, $offset);
        $serverPort = unpack('n', substr($packetData, $offset, 2))[1] ?? 0;
        $offset += 2;
        $nextState = VarInt::read($packetData, $offset);

        PacketLogger::logInfo("Handshake: protocol=$protocolVersion, nextState=$nextState");
        PacketLogger::logInfo("Адрес: $serverAddress, порт: $serverPort");

        $connection->protocol = (int) ($protocolVersion ?? 0);

        $connection->state = match ($nextState) {
            1 => ProtocolState::Status,
            2 => ProtocolState::Login,
            default => ProtocolState::Status,
        };
    }
}

final class StatusHandler implements PacketHandler
{
    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly PacketFactory $packetFactory,
    ) {
    }

    public function handle(ClientConnection $connection, string $packetData): void
    {
        $offset = 0;
        $packetId = VarInt::read($packetData, $offset);

        if ($packetId === 0x00) {
            $this->connections->sendPacket(
                $connection->id,
                0x00,
                $this->packetFactory->createStatusResponse(
                    fn (): int => $this->connections->getConnectionCount()
                )
            );
            PacketLogger::logInfo("Отправлен статус сервера клиенту {$connection->id}");
        } elseif ($packetId === 0x01) {
            $timestamp = substr($packetData, $offset, 8);
            $this->connections->sendPacket($connection->id, 0x01, $timestamp);
        }
    }
}

final class LoginHandler implements PacketHandler
{
    public function __construct(
        private readonly MinecraftServer $server,
        private readonly ConnectionManager $connections,
        private readonly PacketFactory $packetFactory,
        private readonly ServerConfig $config,
    ) {
    }

    /**
     * Encryption Request (login S2C 0x01) — заготовка для online-mode.
     * Формат Protocol 763: serverId(String) + publicKey(VanillaSlot-style
     * byte array = VarInt len + bytes) + verifyToken(byte[4]).
     * В offline-режиме НЕ вызывается: клиент ждёт сразу Login Success.
     */
    private function sendEncryptionRequest(ClientConnection $connection): void
    {
        PacketLogger::logInfo('🔐 Sending Encryption Request (0x01)...');

        // Минимальный RSA-ключ заглушки недостаточно для реальной проверки
        // Mojang — здесь фиксируем контракт пакета; полная реализация
        // требует генерации RSA-пары и сессионной проверки.
        $serverId = '';
        $publicKey = ''; // placeholder: реальный DER SubjectPublicKeyInfo
        $verifyToken = random_bytes(4);

        $data = StringHandler::write($serverId)
            . VarInt::write(strlen($publicKey)) . $publicKey
            . $verifyToken;

        $this->connections->sendPacket(
            $connection->id,
            PacketId::CB_LOGIN_ENCRYPTION_REQUEST,
            $data
        );

        PacketLogger::logInfo('⏳ Waiting for Encryption Response (0x01)...');
    }

    public function handle(ClientConnection $connection, string $packetData): void
    {
        $offset = 0;
        $packetId = VarInt::read($packetData, $offset);

        if ($packetId === PacketId::SB_LOGIN_ENCRYPTION_RESPONSE) {
            PacketLogger::logInfo("📨 Encryption Response (0x01) от клиента {$connection->id}");
            $this->sendLoginSuccess($connection, $connection->username);
            return;
        }

        if ($packetId !== PacketId::SB_LOGIN_START) {
            PacketLogger::logInfo(sprintf(
                '➡️ RECEIVED | Client %d | State: login | Unexpected packet 0x%02X',
                $connection->id,
                $packetId ?? -1
            ));
            return;
        }

        // Парсим имя игрока
        $username = StringHandler::read($packetData, $offset);
        $connection->username = $username;

        // Protocol 763 (1.20.1): после имени всегда идет UUID (16 байт)
        // Игнорируем любую попытку парсить "Has Sig Data" - это ненадежно
        $remainingBytes = strlen($packetData) - $offset;

        if ($remainingBytes >= 16) {
            // Берем последние 16 байт как UUID клиента
            $uuidBytes = substr($packetData, $offset + $remainingBytes - 16, 16);
            $connection->uuid = Uuid::pretty($uuidBytes);
            PacketLogger::logInfo("🎯 Извлечен UUID клиента (последние 16 байт): {$connection->uuid}");
        } else {
            // Fallback для очень старых клиентов
            $connection->uuid = Uuid::fromUsername($username);
            PacketLogger::logInfo("🎲 Fallback: Сгенерирован offline UUID: {$connection->uuid}");
        }

        PacketLogger::logInfo("📝 Login Start: username=$username (клиент {$connection->id})");
        PacketLogger::logInfo("UUID игрока: {$connection->uuid} для клиента {$connection->id}");
        PacketLogger::logInfo('🔐 Online mode: DISABLED');

        // Offline mode — сразу отправляем Login Success
        $this->sendLoginSuccess($connection, $username);
    }

    /**
     * Login Success (login S2C 0x02) Protocol 763:
     * [UUID 16b][Username String][Properties Count VarInt=0].
     * БЕЗ поля Strict Error Handling (появилось только в 1.20.5+/766+).
     */
    /**
     * Login Success (login S2C 0x02) Protocol 763:
     * [UUID 16b][Username String][Properties Count VarInt=0].
     * БЕЗ поля Strict Error Handling (появилось только в 1.20.5+/766+).
     */
    private function sendLoginSuccess(ClientConnection $connection, string $username): void
    {
        $payload = $this->packetFactory->createLoginSuccess($connection->uuid, $username);

        $ok = $this->connections->sendPacket(
            $connection->id,
            PacketId::CB_LOGIN_SUCCESS,
            $payload
        );

        if (!$ok) {
            PacketLogger::logError('❌ Failed to write Login Success');
            return;
        }

        PacketLogger::logInfo(sprintf(
            "✅ Login Success sent клиенту %d",
            $connection->id
        ));

        // Protocol 763 (1.20.1): клиент НЕ шлёт Login Acknowledged (0x03).
        // Он СРАЗУ переходит в Play и первым пакетом шлёт Client Information (0x0A).
        // Поэтому мы НЕ ждём 0x03, а сразу переходим в Play.
        if ($connection->protocol === 763) {
            PacketLogger::logInfo("🎮 Protocol 763 (1.20.1): переход в Play БЕЗ ожидания Login Acknowledged");

            // Мгновенный переход в Play
            $connection->state = ProtocolState::Play;
            $connection->loginSuccessAt = null; // Не нужен таймаут для 763

            // Отправляем игровые пакеты СРАЗУ
            $this->server->sendGamePackets($connection);
        } else {
            // Protocol 764+ (1.20.2+): ждём Login Acknowledged
            $connection->loginSuccessAt = time();
            PacketLogger::logInfo("⏳ Protocol 764+: ожидание Login Acknowledged (0x03)...");
        }
    }
}

final class PlayHandler implements PacketHandler
{
    public function __construct(
        private readonly MinecraftServer $server,
        private readonly ConnectionManager $connections,
        private readonly PacketFactory $packetFactory,
    ) {
    }

    public function handle(ClientConnection $connection, string $packetData): void
    {
        $offset = 0;
        $packetId = VarInt::read($packetData, $offset);

        switch ($packetId) {
            case PacketId::SB_LOGIN_LOGIN_ACKNOWLEDGED:
                // 0x03 в контексте Play — это тоже ACK (клиент подтверждает
                // переход); игнорируем повторно.
                return;

            case PacketId::SB_PLAY_ACCEPT_TELEPORTATION:
                $confirmId = VarInt::read($packetData, $offset);
                PacketLogger::logInfo("Teleport Confirm от клиента {$connection->id}: id=$confirmId");
                $connection->pendingKeepAliveId = null;
                // После подтверждения телепорта — отдаём чанки (лениво).
                $this->server->sendChunksIfNeeded($connection);
                return;

            case PacketId::SB_PLAY_KEEP_ALIVE:
                $keepAliveId = unpack('J', substr($packetData, $offset, 8))[1] ?? 0;
                PacketLogger::logInfo("Keep Alive получен от клиента {$connection->id}: $keepAliveId");
                $connection->pendingKeepAliveId = null;
                return;

            case PacketId::SB_PLAY_MOVE_PLAYER_POS:
            case PacketId::SB_PLAY_MOVE_PLAYER_ROT:
            case PacketId::SB_PLAY_MOVE_PLAYER_POS_ROT: // Position And Rotation (0x18)
            case PacketId::SB_PLAY_MOVE_PLAYER_STATUS_ONLY:
                // Движение игрока — минимальный сервер принимает молча.
                return;

            case PacketId::SB_PLAY_CLIENT_INFORMATION:
                // Client Settings — можно проигнорировать.
                return;

            case PacketId::SB_PLAY_CUSTOM_PAYLOAD:
                $channel = StringHandler::read($packetData, $offset);
                PacketLogger::logInfo("Plugin message от клиента {$connection->id}: $channel");
                return;

            case PacketId::SB_PLAY_PONG:
                return;

            default:
                PacketLogger::logDebug("Play: необработанный пакет 0x" . dechex($packetId) . " от клиента {$connection->id}");
                return;
        }
    }
}

final class PacketDispatcher
{
    /** @var array<string, PacketHandler> */
    private array $handlers = [];

    public function registerHandler(ProtocolState $state, PacketHandler $handler): void
    {
        $this->handlers[$state->value] = $handler;
    }

    public function dispatch(ClientConnection $connection, string $packetData): void
    {
        $handler = $this->handlers[$connection->state->value] ?? null;

        if ($handler === null) {
            PacketLogger::logError("No handler for state: {$connection->state->value}");

            return;
        }

        $handler->handle($connection, $packetData);
    }
}

// ============================================================================
// Конфигурация (readonly properties + constructor promotion)
// ============================================================================

final class ServerConfig
{
    public function __construct(
        public readonly string $host = '0.0.0.0',
        public readonly int $port = 25565,
        public readonly int $protocolVersion = 763,
        public readonly string $serverName = 'PHP Minecraft Hub Server',
        public readonly string $motd = "§a✓ PHP Hub Server §7| §fMinecraft 1.20.1\n§eДобро пожаловать!",
        public readonly int $maxPlayers = 20,
        public readonly int $viewDistance = 8,
        public readonly int $simulationDistance = 8,
        // online-mode = false: offline-сервер, проверка сессий Mojang НЕ выполняется.
        public readonly bool $onlineMode = false,
    ) {
    }

    public function getMotd(): string
    {
        return $this->motd;
    }

    /** @return array{name: string, spawn: array{x: float, y: float, z: float}} */
    public function getWorld(): array
    {
        return WorldGenerator::generateFlatWorld();
    }
}

// ============================================================================
// Сервер (Template Method: mainLoop → accept/process/keepalive)
// ============================================================================

final class MinecraftServer
{
    /** @var resource|null */
    private mixed $socket = null;
    private bool $running = true;
    private readonly ConnectionManager $connectionManager;
    private readonly PacketFactory $packetFactory;
    private readonly PacketDispatcher $packetDispatcher;
    private int $lastKeepAliveAt = 0;
    /** Счётчик тиков событийного цикла для отладочного «пульса». */
    private int $debugTick = 0;

    public function __construct(public readonly ServerConfig $config)
    {
        $this->packetFactory = new PacketFactory($config);
        $this->connectionManager = new ConnectionManager($this);
        $this->packetDispatcher = new PacketDispatcher();

        $this->packetDispatcher->registerHandler(ProtocolState::Handshake, new HandshakeHandler());
        $this->packetDispatcher->registerHandler(
            ProtocolState::Status,
            new StatusHandler($this->connectionManager, $this->packetFactory)
        );
        $this->packetDispatcher->registerHandler(
            ProtocolState::Login,
            new LoginHandler($this, $this->connectionManager, $this->packetFactory, $config)
        );
        $this->packetDispatcher->registerHandler(
            ProtocolState::Play,
            new PlayHandler($this, $this->connectionManager, $this->packetFactory)
        );

        PacketLogger::clearLog();
    }

    public function start(): void
    {
        $address = "tcp://{$this->config->host}:{$this->config->port}";
        $this->socket = @stream_socket_server(
            $address,
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
        );

        if (!is_resource($this->socket)) {
            throw new RuntimeException("Не удалось создать сервер: $errstr ($errno)");
        }

        stream_set_blocking($this->socket, false);

        $this->runSelfTests();

        PacketLogger::logInfo(
            "Minecraft 1.20.1 сервер слушает на {$this->config->host}:{$this->config->port}"
        );
        PacketLogger::logInfo("Протокол: {$this->config->protocolVersion} (1.20.1, БЕЗ Configuration state)");

        $this->mainLoop();
    }

    /**
     * Самотестирование критичных структур протокола (запускается при старте).
     * Проверяет VarInt-кодирование, формат Login Success Protocol 763 и
     * кадрирование пакетов. Битый формат = мгновенная остановка до запуска.
     */
    public function runSelfTests(): void
    {
        $failures = [];

        $check = static function (bool $cond, string $name) use (&$failures): void {
            if (!$cond) {
                $failures[] = $name;
            }
        };

        // --- Валидация констант PacketId (Protocol 763) ---
        PacketLogger::logInfo('🧪 Running PacketId validation...');

        $requiredConstants = [
            'CB_LOGIN_SUCCESS' => 0x02,
            'SB_LOGIN_START' => 0x00,
            'SB_LOGIN_LOGIN_ACKNOWLEDGED' => 0x03,
            'CB_CONFIG_KNOWN_PACKS' => 0x0D,
            'SB_CONFIG_KNOWN_PACKS' => 0x06,
            'CB_CONFIG_FINISH_CONFIGURATION' => 0x02,
            'SB_CONFIG_ACKNOWLEDGE_FINISH_CONFIGURATION' => 0x02,
            'CB_PLAY_LOGIN' => 0x29,
            'CB_PLAY_SET_CONTAINER_CONTENT' => 0x27,
            'CB_PLAY_PLAYER_POSITION' => 0x3A,
            'CB_PLAY_KEEP_ALIVE' => 0x22,
            'CB_PLAY_LEVEL_CHUNK_WITH_LIGHT' => 0x23,
            'CB_PLAY_SYSTEM_CHAT' => 0x65,
            'SB_PLAY_ACCEPT_TELEPORTATION' => 0x00,
            'SB_PLAY_KEEP_ALIVE' => 0x15,
            'SB_PLAY_MOVE_PLAYER_POS' => 0x17,
            'SB_PLAY_MOVE_PLAYER_POS_ROT' => 0x18,
            'SB_PLAY_MOVE_PLAYER_ROT' => 0x19,
        ];

        foreach ($requiredConstants as $const => $expectedValue) {
            $fullConst = "PacketId::{$const}";
            if (!defined($fullConst)) {
                PacketLogger::logError("❌ Constant {$fullConst} not defined!");
                throw new \RuntimeException("Missing PacketId constant: {$const}");
            }
            $actual = constant($fullConst);
            if ($actual !== $expectedValue) {
                PacketLogger::logError(sprintf(
                    '❌ PacketId::%s = 0x%02X, ожидалось 0x%02X (Protocol 763)',
                    $const,
                    $actual,
                    $expectedValue
                ));
                $failures[] = "PacketId::{$const} value mismatch";
                continue;
            }
            PacketLogger::logInfo("  ✅ {$const} = 0x" . dechex($actual));
        }

        PacketLogger::logInfo('✅ All required PacketId constants are defined!');

        // --- VarInt encoding ---
        $check(bin2hex(VarInt::write(0)) === '00', 'VarInt(0)');
        $check(bin2hex(VarInt::write(127)) === '7f', 'VarInt(127)');
        $check(bin2hex(VarInt::write(128)) === '8001', 'VarInt(128)');
        $check(bin2hex(VarInt::write(763)) === 'fb05', 'VarInt(763) failed');
        $check(bin2hex(VarInt::write(2097151)) === 'ffff7f', 'VarInt(2097151)');

        // --- Login Success структура (Protocol 763) ---
        // Формат: [UUID 16 bytes][Username String][Properties Count VarInt(0)]
        // БЕЗ поля Strict Error Handling (только 1.20.5+ / protocol 765).
        $uuid = hex2bin('9ecc42c83e934cf79ed15c4c22f67ac7');
        $username = StringHandler::write('turel1983'); // 0a + 'turel1983' = 10 байт
        $props = VarInt::write(0);
        $payload = $uuid . $username . $props;

        // Побайтный эталон Protocol 763:
        //   data    = UUID(16) + len(0x0a) + "turel1983"(9) + props(0x00) = 28 байт
        //   frame   = len(0x1d) + id(0x02) + data = 30 байт
        // (В эталонной строке пользователя из задачи длина строки была
        // записана как 0x09 — это опечатка: 'turel1983' = 9 символов,
        //  но корректный VarInt-префикс строки = 0x09 только для 9 байт;
        //  сверка ведётся по фактической длине имени.)
        $expectedDataHex = '9ecc42c83e934cf79ed15c4c22f67ac7' . '09' . bin2hex('turel1983') . '00';
        $expectedFrame = PacketCodec::wrapPacket(0x02, hex2bin($expectedDataHex));

        $check(strlen($payload) === strlen($uuid) + 1 + strlen('turel1983') + 1, 'Login Success payload layout');
        $check(bin2hex($payload) === $expectedDataHex, 'Login Success data hex mismatch: ' . bin2hex($payload));
        $check(bin2hex($actual = PacketCodec::wrapPacket(0x02, $payload)) === bin2hex($expectedFrame), 'Login Success frame mismatch');
        $check(strlen($expectedFrame) === strlen($payload) + 2, 'Login Success frame = data + 2 header bytes');

        // Проверка, что реальный factory produces ровно эту структуру.
        $factory = new PacketFactory($this->config);
        $fromFactory = PacketCodec::wrapPacket(
            0x02,
            $factory->createLoginSuccess('9ecc42c8-3e93-4cf7-9ed1-5c4c22f67ac7', 'turel1983')
        );
        $check($fromFactory === $expectedFrame, 'PacketFactory::createLoginSuccess mismatch');

        // --- Кадрирование round-trip ---
        $frame = PacketCodec::wrapPacket(0x03, '');
        $check($frame === "\x01\x03", 'Empty packet framing (Login Acknowledged echo)');
        $unwrapped = PacketCodec::unwrapPacket($frame);
        $check($unwrapped === [0x03, ''], 'unwrapPacket round-trip');

        // --- Online mode ---
        $check($this->config->onlineMode === false, 'online-mode must be false');

        if ($failures !== []) {
            foreach ($failures as $f) {
                PacketLogger::logError("❌ SelfTest FAILED: $f");
            }
            throw new RuntimeException('Самотесты провалены: ' . implode('; ', $failures));
        }

        PacketLogger::logInfo('✅ SelfTests passed: VarInt(763)=fb05, LoginSuccess=30 bytes (28 data + 2 header), online-mode=false');
    }

    private function mainLoop(): void
    {
        while ($this->running) {
            $this->debugTick++;

            // Отладочный «пульс»: каждые ~100 тиков (≈1 сек при 10мс цикле)
            // подтверждаем, что событийный цикл жив и не завис.
            if ($this->debugTick % 100 === 0) {
                PacketLogger::logDebug(
                    '💓 Server loop alive. Active connections: '
                    . $this->connectionManager->getConnectionCount()
                );
            }

            $this->acceptConnections();
            $this->connectionManager->processConnections();
            $this->connectionManager->tickDelayedPackets();
            $this->tickChunkStreaming();
            $this->connectionManager->flushOutBuffers();
            $this->tickEarlyKeepAlives();
            $this->tickKeepAlives();
            usleep(10000); // 10 мс событийный цикл
        }
    }

    /** Потоковая доотправка чанков клиентам в состоянии Play. */
    private function tickChunkStreaming(): void
    {
        foreach ($this->connectionManager->getConnections() as $connection) {
            if ($connection->state === ProtocolState::Play && !$connection->chunksSent) {
                $this->sendChunksIfNeeded($connection);
            }
        }
    }

    private function acceptConnections(): void
    {
        if (!is_resource($this->socket)) {
            return;
        }

        $client = @stream_socket_accept($this->socket, 0);
        $this->connectionManager->acceptNewConnection($client);
    }

    /** Периодический Keep Alive в Play (раз в 15 секунд, неблокирующе). */
    private function tickKeepAlives(): void
    {
        $now = time();

        if ($now - $this->lastKeepAliveAt < 15) {
            return;
        }

        $this->lastKeepAliveAt = $now;

        foreach ($this->connectionManager->getConnections() as $clientId => $connection) {
            if ($connection->state !== ProtocolState::Play) {
                continue;
            }

            if ($connection->pendingKeepAliveId !== null && $now - $connection->lastActivity > 60) {
                PacketLogger::logConnection("Клиент $clientId не ответил на Keep Alive — закрытие");
                $this->connectionManager->closeConnection($clientId);
                continue;
            }

            $keepAlive = $this->packetFactory->createKeepAlive();
            $connection->pendingKeepAliveId = unpack('J', $keepAlive)[1];
            $this->connectionManager->sendPacket($clientId, PacketId::PLAY_KEEP_ALIVE, $keepAlive);
        }
    }

    /**
     * Быстрый первый Keep Alive: через ~2 секунды после входа в Play,
     * чтобы тестовые клиенты (с коротким окном чтения) успевали получить
     * CB Play Keep Alive (0x22) без ожидания 15-секундного тика.
     */
    private function tickEarlyKeepAlives(): void
    {
        $now = time();

        foreach ($this->connectionManager->getConnections() as $clientId => $connection) {
            if ($connection->state !== ProtocolState::Play) {
                continue;
            }

            // pendingKeepAliveId === null и lastActivity недавно — свежий игрок.
            if ($connection->pendingKeepAliveId !== null) {
                continue;
            }

            if ($now - $connection->lastActivity > 2 && $now - $connection->lastActivity < 15) {
                $keepAlive = $this->packetFactory->createKeepAlive();
                $connection->pendingKeepAliveId = unpack('J', $keepAlive)[1];
                $this->connectionManager->sendPacket($clientId, PacketId::PLAY_KEEP_ALIVE, $keepAlive);
                PacketLogger::logInfo("⏱️ Early Keep Alive (0x22) отправлен клиенту $clientId");
            }
        }
    }

    public function processBuffer(int $clientId, ClientConnection $connection): void
    {
        $buffer = $connection->buffer;

        while (strlen($buffer) > 0) {
            $offset = 0;
            $packetLength = VarInt::read($buffer, $offset);

            if ($packetLength === null) {
                break; // неполная длина — ждём данных
            }

            if ($packetLength < 0 || strlen($buffer) < $offset + $packetLength) {
                break; // неполный пакет — ждём данных
            }

            $packetData = substr($buffer, $offset, $packetLength);
            $buffer = substr($buffer, $offset + $packetLength);

            $this->handlePacket($clientId, $connection, $packetData);
        }

        $connection->buffer = $buffer;
    }

    private function handlePacket(int $clientId, ClientConnection $connection, string $packetData): void
    {
        $offset = 0;
        $packetId = VarInt::read($packetData, $offset);

        if ($packetId === null) {
            return;
        }

        PacketLogger::logPacket('➡️ RECEIVED', $clientId, $packetId, $packetData);

        try {
            // Перехват Login Acknowledged (0x03) в состоянии Login:
            // ТОЛЬКО для Protocol 764+ (1.20.2+)
            if ($connection->state === ProtocolState::Login
                && $packetId === PacketId::SB_LOGIN_LOGIN_ACKNOWLEDGED
                && $connection->protocol >= 764
            ) {
                $this->onLoginAcknowledged($connection);
                return;
            }

            // Перехват Login Plugin Response (0x02) — ответ на fallback-запрос
            // minecraft:brand. В 763 ACK приходит независимо, поэтому здесь
            // только логируем и гасим pending-запрос.
            if ($connection->state === ProtocolState::Login && $packetId === PacketId::SB_LOGIN_PLUGIN_RESPONSE) {
                $connection->pluginRequestState = 'answered';
                PacketLogger::logInfo("✅ Login Plugin Response (0x02) от клиента $clientId — brand подтверждён");

                return;
            }

            $this->packetDispatcher->dispatch($connection, $packetData);
        } catch (Throwable $e) {
            PacketLogger::logError(
                'Ошибка обработки пакета 0x' . dechex($packetId) . ': ' . $e->getMessage()
            );
        }
    }

    /**
     * ПРОТОКОЛ 763: после Login Acknowledged сервер ОБЯЗАН немедленно
     * отправить Join Game (play 0x29) и остальную play-инициализацию.
     */
    private function onLoginAcknowledged(ClientConnection $connection): void
    {
        $clientId = $connection->id;
        PacketLogger::logInfo("✅ Получен Login Acknowledged (0x03) от клиента $clientId");
        PacketLogger::logInfo("✅ Login Acknowledged от клиента $clientId — переход НАПРЯМУЮ в Play (1.20.1 без Configuration)");

        // Мгновенный переход Login → Play и отправка стартовых пакетов мира
        // БЕЗ каких-либо задержек/usleep (иначе клиент не получит Join Game
        // и отвалится по таймауту на «Подключение к серверу...»).
        $connection->state = ProtocolState::Play;
        $connection->loginSuccessAt = null; // ACK получен — таймаут больше не нужен
        $this->sendGamePackets($connection);
    }

    /** Последовательность входа в Play (Protocol 763). */
    public function sendGamePackets(ClientConnection $connection): void
    {
        $clientId = $connection->id;

        if (!$this->connectionManager->isClientConnected($clientId)) {
            PacketLogger::logError('Соединение разорвано перед отправкой игровых пакетов');

            return;
        }

        try {
            $spawn = WorldGenerator::generateFlatWorld()['spawn'];
            $entityId = $clientId & 0x7FFFFFFF;

            $this->connectionManager->sendPacket(
                $clientId,
                PacketId::PLAY_JOIN_GAME,
                $this->packetFactory->createJoinGame($entityId)
            );
            PacketLogger::logInfo("Join Game (0x29) отправлен клиенту $clientId");

            $this->connectionManager->sendPacket(
                $clientId,
                PacketId::PLAY_CUSTOM_PAYLOAD,
                $this->packetFactory->createPluginMessageBrand()
            );

            $this->connectionManager->sendPacket(
                $clientId,
                PacketId::PLAY_DECLARE_COMMANDS,
                $this->packetFactory->createDeclareCommands()
            );

            $this->connectionManager->sendPacket(
                $clientId,
                PacketId::PLAY_PLAYER_INFO,
                $this->packetFactory->createPlayerInfoAdd($connection->uuid, $connection->username)
            );
            PacketLogger::logInfo("Player Info Add (0x38) отправлен клиенту $clientId");

            $this->connectionManager->sendPacket(
                $clientId,
                PacketId::PLAY_DIFFICULTY,
                $this->packetFactory->createServerDifficulty()
            );

            $this->connectionManager->sendPacket(
                $clientId,
                PacketId::PLAY_UPDATE_TIME,
                $this->packetFactory->createTimeUpdate()
            );

            $this->connectionManager->sendPacket(
                $clientId,
                PacketId::PLAY_SPAWN_POSITION,
                $this->packetFactory->createSpawnPosition(0, -64, 0)
            );

            $this->connectionManager->sendPacket(
                $clientId,
                PacketId::PLAY_POSITION,
                $this->packetFactory->createPlayerPosition($spawn['x'], $spawn['y'], $spawn['z'])
            );
            PacketLogger::logInfo("Position (teleport, 0x3A) отправлен клиенту $clientId");

            $this->connectionManager->sendPacket(
                $clientId,
                PacketId::PLAY_ABILITIES,
                $this->packetFactory->createPlayerAbilities()
            );

            $this->connectionManager->sendPacket(
                $clientId,
                PacketId::PLAY_HELD_ITEM_SLOT,
                $this->packetFactory->createHeldItemChange()
            );

            $this->connectionManager->sendPacket(
                $clientId,
                PacketId::PLAY_UPDATE_HEALTH,
                $this->packetFactory->createUpdateHealth()
            );

            $this->connectionManager->sendPacket(
                $clientId,
                PacketId::PLAY_EXPERIENCE,
                $this->packetFactory->createSetExperience()
            );

            $this->connectionManager->sendPacket(
                $clientId,
                PacketId::PLAY_HURT_ANIMATION,
                $this->packetFactory->createHurtArmor($entityId)
            );

            $this->connectionManager->sendPacket(
                $clientId,
                PacketId::PLAY_SIMULATION_DISTANCE,
                $this->packetFactory->createSimulationDistance($this->config->simulationDistance)
            );

            $this->connectionManager->sendPacket(
                $clientId,
                PacketId::PLAY_GAME_EVENT,
                $this->packetFactory->createGameEvent(13, 0.0) // 13 = start waiting for level chunks
            );
            PacketLogger::logInfo("Game Event 13 (start waiting for chunks) отправлен клиенту $clientId");

            // Чанки отдаём СРАЗУ после Join Game — иначе клиент висит
            // «Downloading terrain». Отправка пачками по кругу событий.
            $this->sendChunksIfNeeded($connection);

            // Первый Keep Alive (0x22) отправляем СРАЗУ после чанков, не
            // дожидаясь 15-секундного тика: тестовые клиенты с коротким
            // окном чтения проверяют его наличие в стартовой последовательности.
            if ($connection->pendingKeepAliveId === null) {
                $keepAlive = $this->packetFactory->createKeepAlive();
                $connection->pendingKeepAliveId = unpack('J', $keepAlive)[1];
                $this->connectionManager->sendPacket(
                    $clientId,
                    PacketId::PLAY_KEEP_ALIVE,
                    $keepAlive
                );
                PacketLogger::logInfo("⏱️ Initial Keep Alive (0x22) отправлен клиенту $clientId");
            }

            $this->connectionManager->sendPacket(
                $clientId,
                PacketId::PLAY_SYSTEM_CHAT,
                $this->packetFactory->createSystemChatMessage("§aДобро пожаловать на PHP Hub Server, {$connection->username}!")
            );

            PacketLogger::logInfo(
                "🎮 Игрок {$connection->username} вошёл в игру (клиент $clientId), "
                . "позиция: {$spawn['x']}, {$spawn['y']}, {$spawn['z']}"
            );
        } catch (Throwable $e) {
            PacketLogger::logError('Ошибка при отправке игровых пакетов: ' . $e->getMessage());
            $this->connectionManager->closeConnection($clientId);
        }
    }

    /**
     * Пакеты состояния Configuration (Protocol 764+, Minecraft 1.20.2+).
     *
     * ВНИМАНИЕ: в Protocol 763 (1.20.1), на котором работает этот сервер,
     * состояния Configuration НЕТ — переход Login → Play происходит напрямую
     * после Login Acknowledged (0x03). Метод является частью конечного
     * автомата Handshake→Status→Login→Configuration→Play и вызывается только
     * при protocolVersion >= 764. Порядок: Registry Data → Feature Flags →
     * Update Tags → Remove Resource Pack → Finish Configuration; клиент
     * отвечает Acknowledge Finish Configuration (0x03) — вход в Play.
     */
    public function sendConfigPackets(ClientConnection $connection): void
    {
        $clientId = $connection->id;

        if (!$this->connectionManager->isClientConnected($clientId)) {
            return;
        }

        try {
            $this->connectionManager->sendPacket(
                $clientId,
                PacketId::CONFIG_FEATURE_FLAGS,
                $this->packetFactory->createFeatureFlags()
            );

            $this->connectionManager->sendPacket(
                $clientId,
                PacketId::CONFIG_REMOVE_RESOURCE_PACK,
                StringHandler::write('empty')
            );

            $this->connectionManager->sendPacket(
                $clientId,
                PacketId::CONFIG_FINISH_CONFIGURATION,
                ''
            );
            PacketLogger::logInfo("Finish Configuration (0x03) отправлен клиенту $clientId — ждём подтверждения");
        } catch (Throwable $e) {
            PacketLogger::logError("Ошибка при отправке конфигурационных пакетов: " . $e->getMessage());
            $this->connectionManager->closeConnection($clientId);
        }
    }

    /** Обработка C2S-пакетов состояния Configuration (для Protocol 764+). */
    public function handleConfigurationPacket(ClientConnection $connection, string $packetData): void
    {
        $offset = 0;
        $packetId = VarInt::read($packetData, $offset);

        switch ($packetId) {
            case PacketId::SB_CONFIG_CLIENT_INFORMATION:
                return;

            case PacketId::SB_CONFIG_KEEP_ALIVE:
                $this->connectionManager->sendPacket(
                    $connection->id,
                    PacketId::CONFIG_KEEP_ALIVE,
                    substr($packetData, $offset, 8)
                );
                return;

            case PacketId::SB_CONFIG_PONG: // C→S ответ на Config Ping (0x03) — эхо
                $this->connectionManager->sendPacket(
                    $connection->id,
                    PacketId::CONFIG_PING,
                    substr($packetData, $offset, 4)
                );
                return;

            case PacketId::SB_CONFIG_PLUGIN_MESSAGE:
                $this->connectionManager->sendPacket(
                    $connection->id,
                    PacketId::CONFIG_PLUGIN_MESSAGE,
                    $this->packetFactory->createPluginMessageBrand()
                );
                return;

            case PacketId::SB_CONFIG_ACKNOWLEDGE_FINISH_CONFIGURATION:
                PacketLogger::logInfo("Клиент {$connection->id} подтвердил конфигурацию — вход в Play");
                $connection->state = PacketState::Play;
                $this->sendGamePackets($connection);
                return;
        }
    }

    /** Повторная обработка пакета уже в состоянии Configuration. */
    public function reprocessInConfigState(ClientConnection $connection, string $packetData): void
    {
        $this->handleConfigurationPacket($connection, $packetData);
    }

    /**
     * Ленивая (streaming) отправка чанков вокруг спавна: не более нескольких
     * чанков за один проход цикла событий — чтобы не переполнять send-буфер
     * неблокирующего сокета и не блокировать других клиентов.
     */
    public function sendChunksIfNeeded(ClientConnection $connection, bool $force = false): void
    {
        if ($connection->chunksSent && !$force) {
            return;
        }

        $clientId = $connection->id;
        $radius = WorldGenerator::CHUNK_RADIUS;
        $total = (2 * $radius + 1) * (2 * $radius + 1);
        $perTick = 4; // чанков за один проход цикла

        for ($n = 0; $n < $perTick && $connection->chunkQueueIndex < $total; $n++) {
            if (!$this->connectionManager->isClientConnected($clientId)) {
                return;
            }

            $idx = $connection->chunkQueueIndex;
            $cx = intdiv($idx, 2 * $radius + 1) - $radius;
            $cz = ($idx % (2 * $radius + 1)) - $radius;
            $connection->chunkQueueIndex++;

            $this->connectionManager->sendPacket(
                $clientId,
                PacketId::PLAY_MAP_CHUNK,
                $this->packetFactory->createChunkData($cx, $cz)
            );
        }

        if ($connection->chunkQueueIndex >= $total) {
            $connection->chunksSent = true;
            PacketLogger::logInfo("🗺️ Чанки (радиус $radius, $total шт.) отправлены клиенту $clientId");
        }
    }

    public function stop(): void
    {
        $this->running = false;

        if (is_resource($this->socket)) {
            fclose($this->socket);
            $this->socket = null;
        }

        foreach (array_keys($this->connectionManager->getConnections()) as $clientId) {
            $this->connectionManager->closeConnection($clientId);
        }
    }

    public function getProtocolVersion(): int
    {
        return $this->config->protocolVersion;
    }

    public function getClientCount(): int
    {
        return $this->connectionManager->getConnectionCount();
    }

    public function getConfig(): ServerConfig
    {
        return $this->config;
    }
}

// ============================================================================
// Запуск
// ============================================================================

if (!extension_loaded('json')) {
    PacketLogger::logError('Расширение json не загружено. Требуется для работы сервера.');
    exit(1);
}

if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN' && function_exists('pcntl_signal')) {
    declare(ticks=1);
    pcntl_signal(SIGINT, static function (): void {
        PacketLogger::logInfo('Получен сигнал SIGINT, остановка сервера...');
        exit(0);
    });
}

try {
    PacketLogger::logInfo('🚀 Запуск Minecraft хаб-сервера (1.20.1 / protocol 763)...');
    PacketLogger::logInfo('🔧 PHP версия: ' . phpversion());
    PacketLogger::logInfo('🌍 Операционная система: ' . PHP_OS);

    $config = new ServerConfig();
    $server = new MinecraftServer($config);
    $server->start();
} catch (Throwable $e) {
    PacketLogger::logError('Ошибка: ' . $e->getMessage());
    exit(1);
}
