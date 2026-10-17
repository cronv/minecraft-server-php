<?php

declare(strict_types=1);

/**
 * Minecraft Java Edition 1.20.1 (Protocol 763) хаб-сервер на чистом PHP 8.2.
 *
 * Точка входа: php server.php
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
final class PacketId
{
    // --- Server-bound (C→S) Login ---
    public const CS_LOGIN_START = 0x00;
    public const CS_LOGIN_ACKNOWLEDGED = 0x03;

    // --- Server-bound (C→S) Configuration ---
    public const CS_CONFIG_CLIENT_INFORMATION = 0x00;
    public const CS_CONFIG_KEEP_ALIVE = 0x01;
    public const CS_CONFIG_PING = 0x02;
    public const CS_CONFIG_PLUGIN_MESSAGE = 0x03;
    public const CS_CONFIG_FINISH_ACK = 0x04;

    // --- Server-bound (C→S) Play ---
    public const CS_PLAY_CONFIRM_TELEPORT = 0x19;
    public const CS_PLAY_KEEP_ALIVE = 0x12;
    public const CS_PLAY_CHAT = 0x03;
    public const CS_PLAY_SWING_ARM = 0x1B;
    public const CS_PLAY_CLIENT_TICK = 0x0A;

    // --- Client-bound (S→C) Login ---
    public const LOGIN_SUCCESS = 0x02;

    // --- Client-bound (S→C) Configuration ---
    public const CONFIG_CLIENT_INFORMATION = 0x00;
    public const CONFIG_KEEP_ALIVE = 0x01;
    public const CONFIG_PING = 0x02;
    public const CONFIG_PLUGIN_MESSAGE = 0x03;
    public const CONFIG_FINISH_CONFIGURATION = 0x04;
    public const CONFIG_REGISTRY_DATA = 0x07;
    public const CONFIG_REMOVE_RESOURCE_PACK = 0x05;
    public const CONFIG_FEATURE_FLAGS = 0x0C;
    public const CONFIG_UPDATE_TAGS = 0x0D;

    // --- Client-bound (S→C) Play ---
    public const PLAY_SPAWN_ENTITY = 0x01;
    public const PLAY_PLUGIN_MESSAGE = 0x17;
    public const PLAY_SET_DEATH_LOCATION = 0x1E;
    public const PLAY_SYSTEM_CHAT_MESSAGE = 0x1F;
    public const PLAY_PING = 0x20;
    public const PLAY_KEEP_ALIVE = 0x21;
    public const PLAY_JOIN_GAME = 0x26;
    public const PLAY_PLAYER_INFO = 0x2D;
    public const PLAY_SEARCH_CREATIVE_TAB = 0x2E;
    public const PLAY_SERVER_DIFFICULTY = 0x33;
    public const PLAY_EMPTY_CHUNK_SNAPSHOT = 0x34;
    public const PLAY_TIME_OF_DAY = 0x5B;
    public const PLAY_PLAYER_POSITION = 0x38;
    public const PLAY_HELD_ITEM_CHANGE = 0x3C;
    public const PLAY_CREATE_SCREEN = 0x3E;
    public const PLAY_SET_HEALTH = 0x3F;
    public const PLAY_HURT_ARMOR = 0x40;
    public const PLAY_INT_1B_EFFECT = 0x42;
    public const PLAY_SET_TITLE_TEXT = 0x44;
    public const PLAY_PLAYER_ABILITIES = 0x45;
    public const PLAY_SET_EXPERIENCE = 0x47;
    public const PLAY_PLAYER_COMPOSITION = 0x48;
    public const PLAY_SET_COOLDOWN = 0x4A;
    public const PLAY_SET_DEFAULT_SPAWN_POSITION = 0x4E;
    public const PLAY_DECLARE_COMMANDS = 0x4F;
    public const PLAY_MULTI_BLOCK_CHANGE = 0x51;
    public const PLAY_UNLOAD_CHUNK = 0x53;
    public const PLAY_BLOCK_UPDATE = 0x54;
    public const PLAY_MAP_ID = 0x55;
    public const PLAY_SET_ACTION_BAR_TEXT = 0x57;
    public const PLAY_WORLD_BORDER_CENTER = 0x58;
    public const PLAY_SECTION_BLOCK_UPDATE = 0x59;
    public const PLAY_CHUNK_DATA = 0x5A;
    public const PLAY_SYNC_PLAYER_POSITION = 0x5E;
    public const PLAY_GAME_EVENT = 0x1B;

    private function __construct()
    {
    }
}

/**
 * Состояния протокола (State pattern).
 */
enum ProtocolState: string
{
    case Handshake = 'handshake';
    case Status = 'status';
    case Login = 'login';
    case LoginAck = 'login_ack';
    case Config = 'config';
    case Play = 'play';
}

// ============================================================================
// Логирование
// ============================================================================

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
        $names = [
            0x00 => 'Handshake/Status Ping',
            0x01 => 'Status/Ping Request',
            0x02 => 'Login Success / Plugin Message',
            0x03 => 'Login Acknowledged / Finish Configuration',
            0x04 => 'Config Keep Alive',
            0x05 => 'Play: Acknowledge Teleport',
            0x07 => 'Registry Data',
            0x0C => 'Feature Flags',
            0x0D => 'Update Tags / Remove Resource Pack',
            0x17 => 'Play: Plugin Message',
            0x1E => 'Set Death Location',
            0x1F => 'System Chat Message',
            0x20 => 'Play: Ping',
            0x21 => 'Keep Alive',
            0x26 => 'Join Game',
            0x2D => 'Player Info',
            0x2E => 'Search Creative Tab Contents',
            0x33 => 'Server Difficulty',
            0x34 => 'Empty Chunk Snapshot',
            0x38 => 'Player Position and Look',
            0x3F => 'Set Health',
            0x40 => 'Hurt Armor',
            0x42 => 'Play Effect (by int)',
            0x45 => 'Player Abilities',
            0x48 => 'Player Composition',
            0x4E => 'Set Default Spawn Position',
            0x5B => 'Time Update',
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
    }

    public static function logDebug(string $message): void
    {
        echo "🔍 $message\n";
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

final class UUIDGenerator
{
    public static function generate(string $username): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%012x',
            mt_rand(0, 0xFFFF),
            mt_rand(0, 0xFFFF),
            mt_rand(0, 0xFFFF),
            mt_rand(0, 0x0FFF) | 0x4000,
            mt_rand(0, 0x3FFF) | 0x8000,
            crc32($username)
        );
    }

    public static function toBinary(string $uuid): string
    {
        return hex2bin(str_replace('-', '', $uuid));
    }
}

// ============================================================================
// NBT (Strategy: компактный DSL для построения tree-структур)
// ============================================================================

/**
 * Корректный сетевой NBT-писатель для Protocol 763.
 *
 * ВАЖНО: в сетевом формате имена тегов — это unsigned short (2 байта,
 * big-endian), а НЕ VarInt. Прежняя реализация использовала VarInt, из-за
 * чего всё дерево реестров было невалидным и клиент бесконечно оставался
 * на этапе конфигурации («Идет подключение…» без перехода в игру).
 */
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

    /** @var string Аккумулирующий байтовый буфер (публичный для низкоуровневых вставок). */
    public string $buf = '';

    /** Собирает корневой компаунд (Network NBT: type + пустое имя). */
    public static function root(callable $fn): string
    {
        $nbt = new self();
        $nbt->compound('', $fn);

        return $nbt->buf;
    }

    /** @param array<int, callable> $entries По каждой вызывается $fn(Nbt). */
    public static function listCompoundOf(string $name, array $entries): string
    {
        $nbt = new self();
        $nbt->listCompound($name, $entries);

        return $nbt->buf;
    }

    /** Разворачивает ассоциативный массив в NBT-компаунд (рекурсивно). */
    public function fromArray(string $name, array $value): self
    {
        $this->compound($name, function (Nbt $n) use ($value): void {
            foreach ($value as $k => $v) {
                $n->writeValue((string) $k, $v);
            }
        });

        return $this;
    }

    /** Записывает скалярное значение или вложенный массив как компаунд. */
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

    /**
     * Хелпер: записать в NBT-буфер массив примитивного типа (byte/short/int/long).
     * В сетевом NBT int[]/long[] кодируются как List<типа>, поэтому нужен отдельный путь.
     */
    public function primitiveArray(string $name, int $type, array $values): self
    {
        $this->tagHeader(self::T_LIST, $name);
        $this->buf .= chr($type) . pack('N', count($values));

        $format = match ($type) {
            self::T_BYTE => 'C',   // signed byte — но для 0..255 используем C
            self::T_SHORT => 'n',
            self::T_INT => 'N',
            self::T_LONG => 'J',
            default => throw new InvalidArgumentException('Bad primitive array type'),
        };

        foreach ($values as $v) {
            $this->buf .= pack($format, $v & 0xFFFFFFFF);
        }

        return $this;
    }

    /** Список int-значений (T_INT array) — используется в Chunk Data. */
    public function intArray(string $name, array $values): self
    {
        return $this->primitiveArray($name, self::T_INT, $values);
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
            $this->compound('', $entry);
        }

        return $this;
    }

    public function byte(string $name, int $value): self
    {
        $this->tagHeader(self::T_BYTE, $name);
        $this->buf .= pack('c', $value);

        return $this;
    }

    public function int(string $name, int $value): self
    {
        $this->tagHeader(self::T_INT, $name);
        $this->buf .= pack('N', $value);

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

    private function tagHeader(int $type, string $name): void
    {
        $this->buf .= chr($type) . pack('n', strlen($name)) . $name;
    }
}

/**
 * Реестры и теги 1.20.1 — неизменяемые данные, сериализуются ОДИН раз и
 * переиспользуются для всех клиентов (паттерн Flyweight: общий байтовый пул).
 */
final class RegistryData
{
    /** @var array<int, array{name: string, params: array}> Биомы 1.20.1 (id 0..62), точные данные vanilla. */
    private const BIOMES = [
        ['name' => 'ocean', 'params' => ['depth' => -1.0, 'temperature' => 0.5, 'downfall' => 0.5]],
        ['name' => 'plains', 'params' => ['depth' => 0.125, 'temperature' => 0.8, 'downfall' => 0.4]],
        ['name' => 'desert', 'params' => ['depth' => 0.125, 'temperature' => 2.0, 'downfall' => 0.0]],
        ['name' => 'windswept_hills', 'params' => ['depth' => 0.45, 'temperature' => 0.2, 'downfall' => 0.3]],
        ['name' => 'forest', 'params' => ['depth' => 0.1, 'temperature' => 0.7, 'downfall' => 0.8]],
        ['name' => 'taiga', 'params' => ['depth' => 0.2, 'temperature' => 0.25, 'downfall' => 0.8]],
        ['name' => 'swamp', 'params' => ['depth' => -0.2, 'temperature' => 0.8, 'downfall' => 0.9]],
        ['name' => 'river', 'params' => ['depth' => -0.5, 'temperature' => 0.5, 'downfall' => 0.5]],
        ['name' => 'nether_wastes', 'params' => ['depth' => 0.1, 'temperature' => 2.0, 'downfall' => 0.0]],
        ['name' => 'the_end', 'params' => ['depth' => 0.1, 'temperature' => 0.5, 'downfall' => 0.5]],
        ['name' => 'frozen_ocean', 'params' => ['depth' => -1.0, 'temperature' => 0.0, 'downfall' => 0.5]],
        ['name' => 'frozen_river', 'params' => ['depth' => -0.5, 'temperature' => 0.0, 'downfall' => 0.5]],
        ['name' => 'snowy_plains', 'params' => ['depth' => 0.125, 'temperature' => 0.0, 'downfall' => 0.5]],
        ['name' => 'mushroom_fields', 'params' => ['depth' => 0.125, 'temperature' => 0.9, 'downfall' => 1.0]],
        ['name' => 'beach', 'params' => ['depth' => 0.0, 'temperature' => 0.8, 'downfall' => 0.4]],
        ['name' => 'jungle', 'params' => ['depth' => 0.125, 'temperature' => 0.95, 'downfall' => 0.9]],
        ['name' => 'sparse_jungle', 'params' => ['depth' => 0.125, 'temperature' => 0.95, 'downfall' => 0.8]],
        ['name' => 'deep_cold_ocean', 'params' => ['depth' => -1.0, 'temperature' => 0.0, 'downfall' => 0.5]],
        ['name' => 'deep_frozen_ocean', 'params' => ['depth' => -1.0, 'temperature' => 0.5, 'downfall' => 0.5]],
        ['name' => 'deep_lukewarm_ocean', 'params' => ['depth' => -1.0, 'temperature' => 0.5, 'downfall' => 0.5]],
        ['name' => 'lukewarm_ocean', 'params' => ['depth' => -1.0, 'temperature' => 0.5, 'downfall' => 0.5]],
        ['name' => 'warm_ocean', 'params' => ['depth' => -1.0, 'temperature' => 0.5, 'downfall' => 0.5]],
        ['name' => 'desert_lakes', 'params' => ['depth' => 0.125, 'temperature' => 2.0, 'downfall' => 0.0]],
        ['name' => 'gravelly_mountains', 'params' => ['depth' => 0.45, 'temperature' => 0.2, 'downfall' => 0.3]],
        ['name' => 'flower_forest', 'params' => ['depth' => 0.1, 'temperature' => 0.7, 'downfall' => 0.8]],
        ['name' => 'taiga_mountains', 'params' => ['depth' => 0.45, 'temperature' => 0.25, 'downfall' => 0.8]],
        ['name' => 'modified_jungle', 'params' => ['depth' => 0.125, 'temperature' => 0.95, 'downfall' => 0.9]],
        ['name' => 'modified_jungle_edge', 'params' => ['depth' => 0.125, 'temperature' => 0.95, 'downfall' => 0.8]],
        ['name' => 'tall_birch_hills', 'params' => ['depth' => 0.45, 'temperature' => 0.6, 'downfall' => 0.6]],
        ['name' => 'dark_forest', 'params' => ['depth' => 0.1, 'temperature' => 0.7, 'downfall' => 0.8]],
        ['name' => 'snowy_taiga_mountains', 'params' => ['depth' => 0.45, 'temperature' => -0.5, 'downfall' => 0.4]],
        ['name' => 'giant_spruce_taiga_hills', 'params' => ['depth' => 0.45, 'temperature' => 0.25, 'downfall' => 0.8]],
        ['name' => 'modified_gravelly_mountains', 'params' => ['depth' => 0.45, 'temperature' => 0.2, 'downfall' => 0.3]],
        ['name' => 'shifted_sand', 'params' => ['depth' => 0.45, 'temperature' => 0.25, 'downfall' => 0.8]],
        ['name' => 'bamboo_jungle', 'params' => ['depth' => 0.125, 'temperature' => 0.95, 'downfall' => 0.9]],
        ['name' => 'bamboo_jungle_hills', 'params' => ['depth' => 0.45, 'temperature' => 0.95, 'downfall' => 0.9]],
        ['name' => 'sunflower_plains', 'params' => ['depth' => 0.125, 'temperature' => 0.8, 'downfall' => 0.4]],
        ['name' => 'warm_beach', 'params' => ['depth' => 0.0, 'temperature' => 0.8, 'downfall' => 0.4]],
        ['name' => 'scorching_sands', 'params' => ['depth' => 0.125, 'temperature' => 2.0, 'downfall' => 0.0]],
        ['name' => 'snowy_beach', 'params' => ['depth' => 0.0, 'temperature' => 0.05, 'downfall' => 0.3]],
        ['name' => 'shattered_savanna_plateau', 'params' => ['depth' => 1.5, 'temperature' => 1.0, 'downfall' => 0.0]],
        ['name' => 'eroded_badlands', 'params' => ['depth' => 0.125, 'temperature' => 2.0, 'downfall' => 0.0]],
        ['name' => 'ice_spikes', 'params' => ['depth' => 0.425, 'temperature' => 0.0, 'downfall' => 0.5]],
        ['name' => 'old_growth_pine_taiga', 'params' => ['depth' => 0.2, 'temperature' => 0.3, 'downfall' => 0.8]],
        ['name' => 'crimson_forest', 'params' => ['depth' => 0.1, 'temperature' => 2.0, 'downfall' => 0.0]],
        ['name' => 'soul_sand_valley', 'params' => ['depth' => 0.1, 'temperature' => 2.0, 'downfall' => 0.0]],
        ['name' => 'basalt_deltas', 'params' => ['depth' => 0.1, 'temperature' => 2.0, 'downfall' => 0.0]],
        ['name' => 'warped_forest', 'params' => ['depth' => 0.1, 'temperature' => 2.0, 'downfall' => 0.0]],
        ['name' => 'black_swamp', 'params' => ['depth' => -0.2, 'temperature' => 0.8, 'downfall' => 0.9]],
        ['name' => 'dripstone_caves', 'params' => ['depth' => 0.125, 'temperature' => 0.8, 'downfall' => 0.4]],
        ['name' => 'lush_caves', 'params' => ['depth' => 0.125, 'temperature' => 0.9, 'downfall' => 0.9]],
        ['name' => 'deep_dark', 'params' => ['depth' => 0.125, 'temperature' => 0.8, 'downfall' => 0.4]],
        ['name' => 'freezing_ocean', 'params' => ['depth' => -1.0, 'temperature' => 0.0, 'downfall' => 0.5]],
        ['name' => 'cold_ocean', 'params' => ['depth' => -1.0, 'temperature' => 0.0, 'downfall' => 0.5]],
        ['name' => 'lukewarm_deep', 'params' => ['depth' => -1.0, 'temperature' => 0.5, 'downfall' => 0.5]],
        ['name' => 'meadow', 'params' => ['depth' => 0.125, 'temperature' => 0.5, 'downfall' => 0.7]],
        ['name' => 'grove', 'params' => ['depth' => 0.125, 'temperature' => -0.2, 'downfall' => 0.8]],
        ['name' => 'snowy_slopes', 'params' => ['depth' => 0.125, 'temperature' => -0.3, 'downfall' => 0.9]],
        ['name' => 'jagged_peaks', 'params' => ['depth' => 0.125, 'temperature' => -0.7, 'downfall' => 0.9]],
        ['name' => 'frozen_peaks', 'params' => ['depth' => 0.125, 'temperature' => -0.7, 'downfall' => 0.9]],
        ['name' => 'stony_peaks', 'params' => ['depth' => 0.125, 'temperature' => 1.0, 'downfall' => 0.3]],
        ['name' => 'mangrove_swamp', 'params' => ['depth' => -0.2, 'temperature' => 0.8, 'downfall' => 0.9]],
        ['name' => 'pale_garden', 'params' => ['depth' => 0.125, 'temperature' => 0.8, 'downfall' => 0.4]],
    ];

    /** @var array<string, int> sky_color / fog_color / water_color / water_fog_color для биомов по индексу. */
    private const BIOME_COLORS = [
        // дефолт для всех — равнинная палитра; уникальные значения не влияют на вход клиента
        'default' => ['sky' => 0x7A7FF, 'fog' => 0xC0D7F, 'water' => 0x3F76E4, 'water_fog' => 0x50533],
    ];

    /** @var string[] Теги для Update Tags (полный список 1.20.1). */
    private const TAG_REGISTRY_KEYS = [
        'minecraft:worldgen/biome',
        'minecraft:chat_type',
        'minecraft:instrument',
        'minecraft:trim_material',
        'minecraft:trim_pattern',
        'minecraft:wm_wool',
        'minecraft:dye',
        'minecraft:nether_portal',
        'minecraft:water',
        'minecraft:lava',
        'minecraft:mineable/pickaxe',
        'minecraft:needs_stone_tool',
        'minecraft:needs_iron_tool',
        'minecraft:needs_diamond_tool',
        'minecraft:features_has_command_block',
        'minecraft:features_default_range',
        'minecraft:worldgen/tree/type',
        'minecraft:worldgen/world_pillar',
        'minecraft:worldgen/trap_chest_skeleton_can_disguise',
        'minecraft:worldgen/ocean_monument_structure',
        'minecraft:worldgen/swamp_hut_may_place',
        'minecraft:worldgen/desert_pyramid_may_place',
        'minecraft:worldgen/jungle_temple_may_place',
        'minecraft:worldgen/snowy_house_may_place',
        'minecraft:worldgen/badlands_house_may_place',
        'minecraft:worldgen/village/plains',
        'minecraft:worldgen/village/savanna',
        'minecraft:worldgen/village/snowy',
        'minecraft:worldgen/village/taiga',
        'minecraft:worldgen/village/desert',
        'minecraft:worldgen/mineshaft',
        'minecraft:worldgen/ruined_portal',
        'minecraft:worldgen/shipwreck_beached',
        'minecraft:worldgen/shipwreck',
        'minecraft:worldgen/stronghold',
        'minecraft:worldgen/end_city',
        'minecraft:worldgen/ancient_city',
        'minecraft:worldgen/nether_fortress',
        'minecraft:worldgen/nether_bridge',
        'minecraft:worldgen/bastion_remnant',
    ];

    private static ?string $dimensionTypeEntry = null;
    private static ?string $registryDataPayload = null;
    private static ?string $updateTagsPayload = null;
    private static ?string $joinGameDimensionCodec = null;

    /** Готовый NBT корневого компаунда элемента dimension_type. */
    public static function dimensionTypeEntry(): string
    {
        if (self::$dimensionTypeEntry === null) {
            self::$dimensionTypeEntry = Nbt::root(function (Nbt $n): void {
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

        return self::$dimensionTypeEntry;
    }

    /** Growable-колбэк добавления одного биома в список. */
    public static function biomeEntryCallback(int $id): callable
    {
        $biome = self::BIOMES[$id] ?? ['name' => 'plains', 'params' => ['depth' => 0.125, 'temperature' => 0.8, 'downfall' => 0.4]];
        $name = $biome['name'];
        $p = $biome['params'];

        return static function (Nbt $n) use ($id, $name, $p): void {
            $n->compound('element', function (Nbt $e) use ($p): void {
                $e->string('precipitation', 'rain')
                    ->float('depth', (float) $p['depth'])
                    ->float('temperature', (float) $p['temperature'])
                    ->float('downfall', (float) $p['downfall'])
                    ->string('category', 'plains')
                    ->compound('effects', function (Nbt $fx): void {
                        $fx->int('sky_color', 0x7A7FF)
                            ->int('fog_color', 0xC0D7F)
                            ->int('water_color', 0x3F76E4)
                            ->int('water_fog_color', 0x50533);
                    });
            })
                ->int('id', $id)
                ->string('name', 'minecraft:' . $name);
        };
    }

    /** Payload пакета Registry Data (0x07): два реестра. */
    public static function registryDataPayload(): string
    {
        if (self::$registryDataPayload === null) {
            $dimRoot = Nbt::root(function (Nbt $n): void {
                $n->listCompound('value', [
                    function (Nbt $e): void {
                        $e->rawCompoundBody(self::dimensionTypeEntry());
                    },
                ]);
            });

            $biomeEntries = [];
            for ($i = 0; $i < count(self::BIOMES); $i++) {
                $biomeEntries[] = self::biomeEntryCallback($i);
            }
            $biomeRoot = Nbt::root(function (Nbt $n) use ($biomeEntries): void {
                $n->listCompound('value', $biomeEntries);
            });

            self::$registryDataPayload = VarInt::write(2)
                . StringHandler::write('minecraft:dimension_type') . $dimRoot
                . StringHandler::write('minecraft:worldgen/biome') . $biomeRoot;
        }

        return self::$registryDataPayload;
    }

    /** Payload пакета Update Tags (0x0D). */
    public static function updateTagsPayload(): string
    {
        if (self::$updateTagsPayload === null) {
            self::$updateTagsPayload = Nbt::root(function (Nbt $n): void {
                foreach (self::TAG_REGISTRY_KEYS as $key) {
                    $n->listCompound($key, []);
                }
            });
        }

        return self::$updateTagsPayload;
    }

    /** Dimension Codec (корневой NBT) для пакета Join Game. */
    public static function joinGameDimensionCodec(): string
    {
        if (self::$joinGameDimensionCodec === null) {
            $biomeEntries = [];
            for ($i = 0; $i < count(self::BIOMES); $i++) {
                $biomeEntries[] = self::biomeEntryCallback($i);
            }

            self::$joinGameDimensionCodec = Nbt::root(function (Nbt $n) use ($biomeEntries): void {
                $n->listCompound('minecraft:dimension_type', [
                    function (Nbt $e): void {
                        $e->rawCompoundBody(self::dimensionTypeEntry());
                    },
                ])
                    ->listCompound('minecraft:worldgen/biome', $biomeEntries);
            });
        }

        return self::$joinGameDimensionCodec;
    }

    private function __construct()
    {
    }
}

// ============================================================================
// Генерация мира (плоский мир в координатах 1.20.1: y=-64..319)
// ============================================================================

final class WorldGenerator
{
    public const SPAWN_X = 0.5;
    public const SPAWN_Y = -60.0;
    public const SPAWN_Z = 0.5;

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

    public function createLoginSuccess(string $uuid, string $username): string
    {
        // Protocol 763: UUID + Username + Properties(empty).
        // Boolean Strict Error Handling появился только в 1.20.2 (protocol 764).
        return UUIDGenerator::toBinary($uuid) . StringHandler::write($username) . VarInt::write(0);
    }

    public function createFeatureFlags(): string
    {
        return VarInt::write(1) . StringHandler::write('minecraft:vanilla');
    }

    public function createRegistryData(): string
    {
        return RegistryData::registryDataPayload();
    }

    public function createUpdateTags(): string
    {
        return RegistryData::updateTagsPayload();
    }

    public function createPluginMessageBrand(): string
    {
        return StringHandler::write('MC|Brand') . StringHandler::write('vanilla');
    }

    public function createJoinGame(int $entityId): string
    {
        $data = '';

        // Entity ID (int)
        $data .= pack('N', $entityId & 0xFFFFFFFF);

        // Is Hardcore (bool)
        $data .= chr(0);

        // Gamemode (uByte): 0 = Survival
        $data .= chr(0);

        // Previous gamemode (Byte): -1 = нет предыдущего
        $data .= pack('c', -1);

        // World names (Array<String>) — всегда ровно 1
        $data .= VarInt::write(1) . StringHandler::write('minecraft:overworld');

        // Dimension codec (NBT): minecraft:dimension_type + minecraft:worldgen/biome
        $data .= RegistryData::joinGameDimensionCodec();

        // Dimension type (Identifier)
        $data .= StringHandler::write('minecraft:overworld');

        // Dimension name (Identifier) == world name
        $data .= StringHandler::write('minecraft:overworld');

        // Hashed seed (Long)
        $data .= pack('J', 0);

        // Max players (VarInt)
        $data .= VarInt::write($this->config->maxPlayers);

        // View distance (VarInt)
        $data .= VarInt::write($this->config->viewDistance);

        // Simulation distance (VarInt)
        $data .= VarInt::write($this->config->simulationDistance);

        // Reduced debug info (bool)
        $data .= chr(0);

        // Enable respawn screen (bool)
        $data .= chr(1);

        // Do limited crafting / Is debug / Is flat — НЕ отправлять в protocol 763.

        return $data;
    }

    /** Player Info (0x2D), action = ADD_PLAYER. */
    public function createPlayerInfoAdd(string $uuid, string $username): string
    {
        return VarInt::write(0)          // action: add player
            . VarInt::write(1)           // количество игроков
            . UUIDGenerator::toBinary($uuid)
            . StringHandler::write($username)
            . VarInt::write(0)           // properties: 0
            . VarInt::write(0)           // gamemode: survival
            . VarInt::write(0)           // ping
            . chr(0);                    // has display name: false
    }

    /** Declare Commands (0x4F): пустой корень — клиент не крашится на командах. */
    public function createDeclareCommands(): string
    {
        return VarInt::write(0);
    }

    /** Game Event (0x1B): start_wait / game_mode_change_for_all и т.п. */
    public function createGameEvent(int $type, float $value): string
    {
        return chr($type) . pack('G', $value);
    }

    /** Held Item Change (0x3C): slot 0. */
    public function createHeldItemChange(): string
    {
        return pack('c', 0);
    }

    /** Set Experience (0x47): float + int + int. */
    public function createSetExperience(): string
    {
        return pack('G', 0.0) . pack('n', 0) . pack('n', 0);
    }

    /** Player Composition (0x48): entity id + health + food + saturation. */
    public function createPlayerComposition(int $entityId): string
    {
        return pack('N', $entityId & 0xFFFFFFFF) . pack('G', 20.0) . pack('v', 20) . pack('G', 5.0);
    }

    /** Chunk Data (0x5A) для одного чанка 16x16x16 (upgraded scale). */
    public function createChunkData(int $chunkX, int $chunkZ): string
    {
        // header: X, Z
        $data = pack('NN', $chunkX, $chunkZ);

        // Heightmaps NBT (motion_blocking 17 shorts all zeros)
        $hm = Nbt::root(function (Nbt $n): void {
            $n->compound('motion_blocking', function (Nbt $m): void {
                $vals = [];
                for ($i = 0; $i < 17; $i++) {
                    $vals[] = 0;
                }
                $m->buf .= chr(Nbt::T_LIST) . pack('n', strlen('motion_blocking')) . 'motion_blocking'
                    . chr(Nbt::T_SHORT) . pack('N', count($vals));
                foreach ($vals as $v) {
                    $m->buf .= pack('n', $v);
                }
            });
        });
        $data .= $hm;

        // Biomes: VarInt palette length = 1 (single biome), then data longs = 0
        $data .= VarInt::write(0); // no biomes section? Actually we must send per-section data.

        // We'll produce one empty section with all-air blocks using the upgraded schema.
        // For simplicity, write a single chunk section with palette [minecraft:air] and zero data.
        $sections = 18; // -64..320 => 18 sections of 16 blocks in 1.20.1
        $sectionData = '';
        for ($s = 0; $s < $sections; $s++) {
            $blockCount = pack('n', 0); // non-air block count
            // Palette containing air only
            $palette = VarInt::write(1) . VarInt::write(0); // id 0 = minecraft:air
            // Data: 37 longs of zeros
            $dataLongs = str_repeat("\0", 8 * 37);
            $sectionData .= $blockCount . chr(13) /* bits per entry */ . $palette . VarInt::write(37) . $dataLongs;
        }

        $data .= VarInt::write(strlen($sectionData)) . $sectionData;

        // Trust edges (boolean array) – none
        $data .= chr(0);

        // Block entities count
        $data .= VarInt::write(0);

        return $data;
    }

    /** Player Info (0x2D), action = REMOVE_PLAYER. */
    public function createPlayerInfoRemove(string $uuid): string
    {
        return VarInt::write(1)
            . VarInt::write(1)
            . UUIDGenerator::toBinary($uuid);
    }

    public function createSpawnPosition(int $x, int $y, int $z): string
    {
        return pack('NNN', $x, $y, $z) . pack('G', 0.0);
    }

    public function createPlayerPosition(float $x, float $y, float $z): string
    {
        $teleportId = ++$this->teleportIdCounter;

        return pack('E', $x) . pack('E', $y) . pack('E', $z)
            . pack('G', 0.0)             // yaw
            . pack('G', 0.0)             // pitch
            . chr(0)                     // flags: абсолютная позиция
            . VarInt::write($teleportId);
    }

    public function createPlayerAbilities(): string
    {
        // 1.20.1: Flags(byte) + flying speed (float) + walking speed (float)
        return chr(0x01)                 // инвулнерабельность
            . pack('G', 0.05)
            . pack('G', 0.1);
    }

    public function createServerDifficulty(): string
    {
        // Difficulty (uByte: 1 = Easy) + locked (bool: false)
        return chr(0x01) . chr(0x00);
    }

    public function createSetHealth(): string
    {
        return pack('G', 20.0) . pack('G', 16.0) . pack('G', 0.0);
    }

    public function createHurtArmor(): string
    {
        return pack('G', 20.0);
    }

    public function createPlayEffect(): string
    {
        // Effect Id (int) + Target position (Block Position, long)
        return pack('N', 1022) . pack('J', 0);
    }

    public function createExperienceOrb(): string
    {
        return pack('N', 0)              // entity id
            . pack('e', 0.0)
            . pack('e', 0.0)
            . pack('e', 0.0)
            . pack('v', 1);              // count
    }

    public function createTimeUpdate(): string
    {
        return pack('J', 6000) . pack('J', 6000) . chr(1);
    }

    public function createKeepAlive(): string
    {
        return pack('J', random_int(0, PHP_INT_MAX));
    }

    public function createSystemChatMessage(string $text): string
    {
        return StringHandler::write($text) . chr(0); // location: 0 = chat box
    }

    public function createSearchCreativeTab(): string
    {
        return StringHandler::write('minecraft:building_blocks')
            . VarInt::write(0)
            . VarInt::write(0);
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

    /** @param Closure(): void $onProcessHook no-op seam for tests. */
    public function __construct(private readonly MinecraftServer $server)
    {
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

    public function processConnections(): void
    {
        $now = time();

        foreach ($this->connections as $clientId => $connection) {
            if ($now - $connection->lastActivity > 120) {
                PacketLogger::logConnection("Таймаут соединения для клиента $clientId");
                $this->closeConnection($clientId);
                continue;
            }

            $socket = $connection->socket;

            if (!is_resource($socket) || feof($socket)) {
                PacketLogger::logConnection("Соединение разорвано (EOF) для клиента $clientId");
                $this->closeConnection($clientId);
                continue;
            }

            $data = @fread($socket, 65535);
            if ($data === false || $data === '') {
                if (feof($socket)) {
                    PacketLogger::logConnection("Соединение разорвано для клиента $clientId");
                    $this->closeConnection($clientId);
                }
                continue;
            }

            $connection->buffer .= $data;
            $connection->lastActivity = $now;
            $this->server->processBuffer($clientId, $connection);
        }
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

        PacketLogger::logPacket('⬅️ SENT', $clientId, $packetId, $frame);

        $written = @fwrite($connection->socket, $frame);

        if ($written === false || $written !== strlen($frame)) {
            PacketLogger::logError(
                "Ошибка отправки пакета 0x" . dechex($packetId) . " клиенту $clientId"
            );
            $this->closeConnection($clientId);

            return false;
        }

        $connection->lastActivity = time();

        return true;
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
        private readonly MinecraftServer $server,
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
        private readonly ConnectionManager $connections,
        private readonly PacketFactory $packetFactory,
    ) {
    }

    public function handle(ClientConnection $connection, string $packetData): void
    {
        $offset = 0;
        $packetId = VarInt::read($packetData, $offset);

        if ($packetId !== PacketId::CS_LOGIN_START) {
            return;
        }

        $username = StringHandler::read($packetData, $offset);
        $connection->username = $username;

        PacketLogger::logInfo("Login Start: $username (клиент {$connection->id})");

        $uuid = UUIDGenerator::generate($username);
        $connection->uuid = $uuid;
        PacketLogger::logInfo("Сгенерирован UUID: $uuid для клиента {$connection->id}");

        $this->connections->sendPacket(
            $connection->id,
            PacketId::LOGIN_SUCCESS,
            $this->packetFactory->createLoginSuccess($uuid, $username)
        );
        PacketLogger::logInfo("Login Success отправлен клиенту {$connection->id}");

        // Ждём Login Acknowledged (0x03) от клиента перед Configuration.
        $connection->state = ProtocolState::LoginAck;
    }
}

final class LoginAckHandler implements PacketHandler
{
    public function __construct(private readonly MinecraftServer $server)
    {
    }

    public function handle(ClientConnection $connection, string $packetData): void
    {
        $offset = 0;
        $packetId = VarInt::read($packetData, $offset);

        // 1.20.1: клиент шлёт LoginAcknowledged (0x03), НО сервер обязан
        // перейти в Configuration только после получения первого пакета из
        // нового состояния (Client Information / Plugin Message). Пока этого
        // не произошло — молча ждём, повторная обработка Acknowledged
        // игнорируется.
        if ($packetId === PacketId::CS_LOGIN_ACKNOWLEDGED) {
            PacketLogger::logInfo(
                "Login Acknowledged от клиента {$connection->id} — ждём первый пакет Configuration"
            );

            return;
        }

        PacketLogger::logInfo(
            "Первый пакет конфигурации (0x" . dechex($packetId ?? 0)
            . ") от клиента {$connection->id} — переходим в Configuration"
        );
        $connection->state = ProtocolState::Config;
        $this->server->sendConfigPackets($connection);
        // Не теряем содержательный пакет (например Client Information):
        // обрабатываем его уже Config-обработчиком.
        $this->server->reprocessInConfigState($connection, $packetData);
    }
}

final class ConfigHandler implements PacketHandler
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
            case PacketId::CS_CONFIG_CLIENT_INFORMATION:
                return;

            case PacketId::CS_CONFIG_KEEP_ALIVE: // C→S Keep Alive (Long) — эхо
                $keepAliveId = substr($packetData, $offset, 8);
                $this->connections->sendPacket(
                    $connection->id,
                    PacketId::CONFIG_KEEP_ALIVE,
                    $keepAliveId
                );
                return;

            case PacketId::CS_CONFIG_PING: // C→S Config Ping (int) — эхо
                $this->connections->sendPacket(
                    $connection->id,
                    PacketId::CONFIG_PING,
                    substr($packetData, $offset, 4)
                );
                return;

            case PacketId::CS_CONFIG_PLUGIN_MESSAGE: // brand — отвечаем MC|Brand
                $this->connections->sendPacket(
                    $connection->id,
                    PacketId::CONFIG_PLUGIN_MESSAGE,
                    $this->packetFactory->createPluginMessageBrand()
                );
                return;

            case PacketId::CS_CONFIG_FINISH_ACK: // подтверждение конфигурации — вход в Play
                PacketLogger::logInfo("Клиент {$connection->id} подтвердил конфигурацию — вход в Play");
                $connection->state = ProtocolState::Play;
                $this->server->sendGamePackets($connection);
                return;
        }
    }
}

final class PlayHandler implements PacketHandler
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

        switch ($packetId) {
            case PacketId::CS_PLAY_CONFIRM_TELEPORT: // Accept Teleport
                $connection->pendingKeepAliveId = null;
                break;

            case PacketId::CS_PLAY_KEEP_ALIVE:
                $keepAliveId = unpack('J', substr($packetData, $offset, 8))[1] ?? 0;
                PacketLogger::logInfo("Keep Alive получен от клиента {$connection->id}: $keepAliveId");
                $connection->pendingKeepAliveId = null;
                break;

            case PacketId::CS_PLAY_CHAT:
                $message = StringHandler::read($packetData, $offset);
                PacketLogger::logInfo("Чат от клиента {$connection->id}: $message");
                break;

            case PacketId::CS_PLAY_SWING_ARM:
            case PacketId::CS_PLAY_CLIENT_TICK:
                break;
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
        public readonly int $viewDistance = 10,
        public readonly int $simulationDistance = 10,
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

    public function __construct(public readonly ServerConfig $config)
    {
        $this->packetFactory = new PacketFactory($config);
        $this->connectionManager = new ConnectionManager($this);
        $this->packetDispatcher = new PacketDispatcher();

        $this->packetDispatcher->registerHandler(ProtocolState::Handshake, new HandshakeHandler());
        $this->packetDispatcher->registerHandler(
            ProtocolState::Status,
            new StatusHandler($this, $this->connectionManager, $this->packetFactory)
        );
        $this->packetDispatcher->registerHandler(
            ProtocolState::Login,
            new LoginHandler($this->connectionManager, $this->packetFactory)
        );
        $this->packetDispatcher->registerHandler(ProtocolState::LoginAck, new LoginAckHandler($this));
        $this->packetDispatcher->registerHandler(
            ProtocolState::Config,
            new ConfigHandler($this, $this->connectionManager, $this->packetFactory)
        );
        $this->packetDispatcher->registerHandler(
            ProtocolState::Play,
            new PlayHandler($this->connectionManager, $this->packetFactory)
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

        PacketLogger::logInfo(
            "Minecraft 1.20.1 хаб-сервер слушает на {$this->config->host}:{$this->config->port}"
        );
        PacketLogger::logInfo("Протокол: {$this->config->protocolVersion}");

        $this->mainLoop();
    }

    private function mainLoop(): void
    {
        while ($this->running) {
            $this->acceptConnections();
            $this->connectionManager->processConnections();
            $this->tickKeepAlives();
            usleep(10000); // 10 мс — событийный цикл, без блокирующих пауз в обработчиках
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

        PacketLogger::logInfo("Получен пакет 0x" . dechex($packetId) . " от клиента $clientId");
        PacketLogger::logPacket('➡️ RECEIVED', $clientId, $packetId, $packetData);

        try {
            $this->packetDispatcher->dispatch($connection, $packetData);
        } catch (Throwable $e) {
            PacketLogger::logError("Ошибка обработки пакета 0x" . dechex($packetId) . ": " . $e->getMessage());
        }
    }

    /**
     * Пакеты состояния Configuration (Protocol 763).
     * Порядок: Registry Data → Feature Flags → Update Tags →
     * Remove Resource Pack → Finish Configuration.
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
                PacketId::CONFIG_REGISTRY_DATA,
                $this->packetFactory->createRegistryData()
            );
            PacketLogger::logInfo("Registry Data (0x07) отправлен клиенту $clientId");

            $this->connectionManager->sendPacket(
                $clientId,
                PacketId::CONFIG_FEATURE_FLAGS,
                $this->packetFactory->createFeatureFlags()
            );
            PacketLogger::logInfo("Feature Flags (0x0C) отправлен клиенту $clientId");

            $this->connectionManager->sendPacket(
                $clientId,
                PacketId::CONFIG_UPDATE_TAGS,
                $this->packetFactory->createUpdateTags()
            );
            PacketLogger::logInfo("Update Tags (0x0D) отправлен клиенту $clientId");

            $this->connectionManager->sendPacket(
                $clientId,
                PacketId::CONFIG_REMOVE_RESOURCE_PACK,
                StringHandler::write('empty')
            );
            PacketLogger::logInfo("Remove Resource Pack (0x0D) отправлен клиенту $clientId");

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

    /**
     * Последовательность входа в Play (Protocol 763). Отправляется одним
     * заходом без искусственных задержек — TCP гарантирует порядок.
     */
    public function sendGamePackets(ClientConnection $connection): void
    {
        $clientId = $connection->id;
        $username = $connection->username;
        $uuid = $connection->uuid;

        PacketLogger::logInfo("Начинаю отправку игровых пакетов клиенту $clientId...");

        if (!$this->connectionManager->isClientConnected($clientId)) {
            PacketLogger::logError("Соединение разорвано перед началом отправки игровых пакетов");

            return;
        }

        try {
            $spawn = WorldGenerator::generateFlatWorld()['spawn'];

            $this->connectionManager->sendPacket(
                $clientId,
                PacketId::PLAY_JOIN_GAME,
                $this->packetFactory->createJoinGame($clientId)
            );
            PacketLogger::logInfo("Join Game (0x26) отправлен клиенту $clientId");

            $this->connectionManager->sendPacket(
                $clientId,
                PacketId::PLAY_PLUGIN_MESSAGE,
                $this->packetFactory->createPluginMessageBrand()
            );

            $this->connectionManager->sendPacket(
                $clientId,
                PacketId::PLAY_PLAYER_INFO,
                $this->packetFactory->createPlayerInfoAdd($uuid, $username)
            );
            PacketLogger::logInfo("Player Info Add (0x2D) отправлен клиенту $clientId");

            $this->connectionManager->sendPacket(
                $clientId,
                PacketId::PLAY_SEARCH_CREATIVE_TAB,
                $this->packetFactory->createSearchCreativeTab()
            );

            $this->connectionManager->sendPacket(
                $clientId,
                PacketId::PLAY_SERVER_DIFFICULTY,
                $this->packetFactory->createServerDifficulty()
            );

            $this->connectionManager->sendPacket(
                $clientId,
                PacketId::PLAY_EMPTY_CHUNK_SNAPSHOT,
                ''
            );

            $this->connectionManager->sendPacket(
                $clientId,
                PacketId::PLAY_SET_DEATH_LOCATION,
                pack('NNN', 0, 0, 0)
            );

            $this->connectionManager->sendPacket(
                $clientId,
                PacketId::PLAY_TIME_OF_DAY,
                $this->packetFactory->createTimeUpdate()
            );

            $this->connectionManager->sendPacket(
                $clientId,
                PacketId::PLAY_SET_DEFAULT_SPAWN_POSITION,
                $this->packetFactory->createSpawnPosition(0, -60, 0)
            );

            $this->connectionManager->sendPacket(
                $clientId,
                PacketId::PLAY_PLAYER_POSITION,
                $this->packetFactory->createPlayerPosition($spawn['x'], $spawn['y'], $spawn['z'])
            );
            PacketLogger::logInfo("Player Position and Look (0x38) отправлен клиенту $clientId");

            $this->connectionManager->sendPacket(
                $clientId,
                PacketId::PLAY_PLAYER_ABILITIES,
                $this->packetFactory->createPlayerAbilities()
            );

            $this->connectionManager->sendPacket(
                $clientId,
                PacketId::PLAY_HURT_ARMOR,
                $this->packetFactory->createHurtArmor()
            );

            $this->connectionManager->sendPacket(
                $clientId,
                PacketId::PLAY_SET_HEALTH,
                $this->packetFactory->createSetHealth()
            );

            $this->connectionManager->sendPacket(
                $clientId,
                PacketId::PLAY_INT_1B_EFFECT,
                $this->packetFactory->createPlayEffect()
            );

            $this->connectionManager->sendPacket(
                $clientId,
                PacketId::PLAY_PLAYER_COMPOSITION,
                $this->packetFactory->createExperienceOrb()
            );

            $this->connectionManager->sendPacket(
                $clientId,
                PacketId::PLAY_KEEP_ALIVE,
                $this->packetFactory->createKeepAlive()
            );

            $this->connectionManager->sendPacket(
                $clientId,
                PacketId::PLAY_SYSTEM_CHAT_MESSAGE,
                $this->packetFactory->createSystemChatMessage("§aДобро пожаловать на PHP Hub Server, $username!")
            );

            PacketLogger::logInfo(
                "🎮 Игрок $username успешно вошел в игру (клиент $clientId), "
                . "позиция: {$spawn['x']}, {$spawn['y']}, {$spawn['z']}"
            );
        } catch (Throwable $e) {
            PacketLogger::logError("Ошибка при отправке игровых пакетов: " . $e->getMessage());
            $this->connectionManager->closeConnection($clientId);
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

    public function __destruct()
    {
        $this->stop();
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
