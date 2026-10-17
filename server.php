<?php
declare(strict_types=1);

class PacketLogger {
    private static $logFile = 'packets.log';

    public static function logPacket(string $direction, int $clientId, int $packetId, string $data): void {
        $timestamp = date('Y-m-d H:i:s.u');
        $hexData = bin2hex($data);
        $formattedHex = self::formatHex($hexData);
        $packetName = self::getPacketName($packetId);
        $logEntry = sprintf(
            "[%s] %s | Client %d | Packet 0x%02x (%s) | Length: %d bytes\n%s\n",
            $timestamp,
            $direction,
            $clientId,
            $packetId,
            $packetName,
            strlen($data),
            $formattedHex
        );
        file_put_contents(self::$logFile, $logEntry, FILE_APPEND);
    }

    private static function formatHex(string $hex): string {
        $result = '';
        $length = strlen($hex);
        for ($i = 0; $i < $length; $i += 32) {
            $chunk = substr($hex, $i, 32);
            $result .= '  ' . implode(' ', str_split($chunk, 2)) . "\n";
        }
        return rtrim($result);
    }

    public static function getPacketName(int $packetId): string {
        $names = [
            0x00 => 'Status Response',
            0x01 => 'Ping Response',
            0x02 => 'Login Success',
            0x08 => 'Feature Flags',
            0x1F => 'Keep Alive',
            0x26 => 'Join Game',
            0x32 => 'Player Info',
            0x37 => 'Player Position and Look',
            0x39 => 'Player Abilities',
            0x41 => 'Spawn Position',
            0x5A => 'Tags',
            0x22 => 'Chunk Data'
        ];
        return $names[$packetId] ?? 'Unknown';
    }

    public static function clearLog(): void {
        file_put_contents(self::$logFile, '');
    }

    public static function logError(string $message): void {
        $timestamp = date('Y-m-d H:i:s.u');
        $logEntry = "[$timestamp] ❌ ERROR: $message\n";
        file_put_contents(self::$logFile, $logEntry, FILE_APPEND);
        echo "❌ $message\n";
    }

    public static function logInfo(string $message): void {
        echo "ℹ️ $message\n";
    }

    public static function logDebug(string $message): void {
        echo "🔍 $message\n";
    }

    public static function logConnection(string $message): void {
        echo "🌐 $message\n";
    }
}

class VarInt {
    public static function read(string $buffer, int &$offset): ?int {
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
                throw new Exception("VarInt слишком большой");
            }
        } while ($byte & 0x80);

        return $value;
    }

    public static function write(int $value): string {
        $bytes = '';

        do {
            $temp = $value & 0x7F;
            $value >>= 7;

            if ($value != 0) {
                $temp |= 0x80;
            }

            $bytes .= chr($temp);
        } while ($value != 0);

        return $bytes;
    }
}

class StringHandler {
    public static function read(string $buffer, int &$offset): string {
        $length = VarInt::read($buffer, $offset);
        if ($length === null || $length === 0 || $offset + $length > strlen($buffer)) {
            return '';
        }

        $string = substr($buffer, $offset, $length);
        $offset += $length;
        return $string;
    }

    public static function write(string $string): string {
        return VarInt::write(strlen($string)) . $string;
    }
}

class UUIDGenerator {
    /**
     * Детерминированный offline-UUID (как в vanilla Minecraft: MD5 "OfflinePlayer:" + name, версия 3).
     */
    public static function generateOffline(string $username): string {
        $md5 = md5('OfflinePlayer:' . $username);
        // Версия 3
        $md5[12] = '3';
        // Variant RFC4122
        $v = hexdec($md5[16]);
        $v = ($v & 0xB) | 0x8;
        $md5[16] = dechex($v);
        return sprintf('%s-%s-%s-%s-%s',
            substr($md5, 0, 8),
            substr($md5, 8, 4),
            substr($md5, 12, 4),
            substr($md5, 16, 4),
            substr($md5, 20, 12)
        );
    }

    public static function generate(string $username): string {
        return self::generateOffline($username);
    }

    /**
     * Конвертация 16 сырых байт UUID в канонический строковый формат.
     */
    public static function fromBytes(string $bytes): string {
        $hex = bin2hex($bytes);
        return sprintf('%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    public static function toBinary(string $uuid): string {
        $uuid = str_replace('-', '', $uuid);
        return hex2bin($uuid);
    }
}

class NBTWriter {
    private $buffer = '';

    public function writeByte(int $value): self {
        $this->buffer .= pack('c', $value);
        return $this;
    }

    public function writeInt(int $value): self {
        $this->buffer .= pack('N', $value);
        return $this;
    }

    public function writeString(string $value): self {
        $length = strlen($value);
        $this->writeVarInt($length);
        $this->buffer .= $value;
        return $this;
    }

    public function writeVarInt(int $value): self {
        $bytes = VarInt::write($value);
        $this->buffer .= $bytes;
        return $this;
    }

    public function writeSimpleTags(): string {
        $this->buffer = '';

        // Корневой тег
        $this->writeByte(10); // TAG_Compound
        $this->writeString(''); // Имя

        // blocks
        $this->writeByte(10); $this->writeString('blocks');
        $this->writeByte(9); $this->writeString('minecraft:block');
        $this->writeByte(10); $this->writeInt(0); // Пустой список
        $this->writeByte(0); // Конец составного тега
        $this->writeByte(0); // Конец составного тега

        // items
        $this->writeByte(10); $this->writeString('items');
        $this->writeByte(9); $this->writeString('minecraft:item');
        $this->writeByte(10); $this->writeInt(0); // Пустой список
        $this->writeByte(0); // Конец составного тега
        $this->writeByte(0); // Конец составного тега

        // fluids
        $this->writeByte(10); $this->writeString('fluids');
        $this->writeByte(9); $this->writeString('minecraft:fluid');
        $this->writeByte(10); $this->writeInt(0); // Пустой список
        $this->writeByte(0); // Конец составного тега
        $this->writeByte(0); // Конец составного тега

        // entity_types
        $this->writeByte(10); $this->writeString('entity_types');
        $this->writeByte(9); $this->writeString('minecraft:entity_type');
        $this->writeByte(10); $this->writeInt(0); // Пустой список
        $this->writeByte(0); // Конец составного тега
        $this->writeByte(0); // Конец составного тега

        // block_entity_types
        $this->writeByte(10); $this->writeString('block_entity_types');
        $this->writeByte(9); $this->writeString('minecraft:block_entity_type');
        $this->writeByte(10); $this->writeInt(0); // Пустой список
        $this->writeByte(0); // Конец составного тега
        $this->writeByte(0); // Конец составного тега

        // game_events
        $this->writeByte(10); $this->writeString('game_events');
        $this->writeByte(9); $this->writeString('minecraft:game_event');
        $this->writeByte(10); $this->writeInt(0); // Пустой список
        $this->writeByte(0); // Конец составного тега
        $this->writeByte(0); // Конец составного тега

        // point_of_interest_types
        $this->writeByte(10); $this->writeString('point_of_interest_types');
        $this->writeByte(9); $this->writeString('minecraft:point_of_interest_type');
        $this->writeByte(10); $this->writeInt(0); // Пустой список
        $this->writeByte(0); // Конец составного тега
        $this->writeByte(0); // Конец составного тега

        $this->writeByte(0); // Конец корневого составного тега
        return $this->buffer;
    }

    public function getBuffer(): string {
        return $this->buffer;
    }

    public function reset(): void {
        $this->buffer = '';
    }
}

class WorldGenerator {
    public static function generateFlatWorld(): array {
        return [
            'name' => 'minecraft:overworld',
            'seed' => crc32("default_seed"),
            'spawn' => ['x' => 0.5, 'y' => 64.0, 'z' => 0.5],
        ];
    }

    public static function generateFlatChunk(): string {
        $chunkData = '';

        // Chunk coordinates (big-endian)
        $chunkData .= pack('N', 0); // Chunk X
        $chunkData .= pack('N', 0); // Chunk Z

        // Heightmaps (NBT TAG_Compound, big-endian)
        // Пол — grass_block на y=64 => индекс в long-массиве: (64 - (-64)) + 1 = 129 -> long[2], bit 1
        $heightmapLongs = [0, 0, 2, 0]; // 4 longs покрывают индексы 0..255
        $mkLongArray = function (array $longs): string {
            $s = pack('c', 10);              // element type = TAG_Long
            $s .= pack('N', count($longs));  // count
            foreach ($longs as $l) {
                $s .= pack('J', $l);         // TAG_Long (8 bytes, big-endian)
            }
            return $s;
        };
        $hm  = "\x0a";                       // root TAG_Compound
        $hm .= "\x00\x0e";                   // name "MOTION_BLOCKING" (len 14)
        $hm .= 'MOTION_BLOCKING';
        $hm .= $mkLongArray($heightmapLongs);
        $hm .= "\x00\x0d";                   // name "WORLD_SURFACE" (len 13)
        $hm .= 'WORLD_SURFACE';
        $hm .= $mkLongArray($heightmapLongs);
        $hm .= "\x00";                       // end root compound
        $chunkData .= $hm;

        // Chunk sections (with biome palettes inside each section)
        $chunkData .= self::generateChunkSections();

        // Block entities count
        $chunkData .= VarInt::write(0);

        // 1.20.1: Tailer data (light trust / middle bits) — пусто
        $chunkData .= VarInt::write(0);

        return $chunkData;
    }

    private static function generateChunkSections(): string {
        $sections = '';

        // Sections count — 19 секций по 16 блоков (y=-64..255), обязательное поле
        $sections .= VarInt::write(19);

        // Заполняем нижнюю половину (секции 0..7, y=-64..63) камнем,
        // секцию 8 (y=64..79) — травой (grass_block), выше — воздух (пустые секции).
        for ($i = 0; $i < 19; $i++) {
            if ($i <= 8) {
                $sections .= "\x01"; // is not empty

                $blockName = ($i === 8) ? 'minecraft:grass_block' : 'minecraft:stone';

                // Palette + block data
                $sections .= self::writeSectionData($blockName);

                // Biome palette + data (single-value palette, 0 bits per entry)
                $sections .= VarInt::write(1);                 // palette size
                $sections .= VarInt::write(1);                 // biome id: plains
                $sections .= VarInt::write(0);                 // bits per entry
                $sections .= VarInt::write(0);                 // data array length = 0

                // Block light: 2048 bytes (VarInt len + data)
                $dark = str_repeat("\x00", 2048);
                $sections .= VarInt::write(strlen($dark)) . $dark;

                // Sky light: все 15 (0xff)
                $bright = str_repeat("\xff", 2048);
                $sections .= VarInt::write(strlen($bright)) . $bright;
            } else {
                $sections .= "\x00"; // empty section (воздух)
            }
        }

        return $sections;
    }

    private static function writeSectionData(string $blockName): string {
        $out = '';

        // Палитра из одного блока: клиент НЕ читает имена палитры при single-value
        $out .= VarInt::write(1);                       // palette size = 1
        $out .= VarInt::write(0);                       // block state id (air/stone/grass_block = id 0 в vanilla-палитре)

        // Для single-value палитры: bits per block = 0, data array length = 0
        $out .= VarInt::write(0);                       // bits per entry (0)
        $out .= VarInt::write(0);                       // data array length

        return $out;
    }
}

class PacketFactory {
    private MinecraftServer $server;

    public function __construct(MinecraftServer $server) {
        $this->server = $server;
    }

    public function createJoinGame(int $clientId, string $uuid): string {
        $data = '';

        // Entity ID (int, 32-bit, big-endian)
        $data .= pack('N', 1); // 1 = player entity ID

        // Player Info (UUID as 16 raw bytes) — требуется в 1.19.3+ (включая 1.20.1)
        if ($uuid !== '') {
            $data .= UUIDGenerator::toBinary($uuid);
        } else {
            $data .= str_repeat("\x00", 16);
        }

        // Hardcore (bool)
        $data .= pack('c', 0); // false

        // Gamemode (unsigned byte) — 0 = Survival
        $data .= pack('C', 0);

        // Previous gamemode (unsigned byte) — 255 = no previous
        $data .= pack('C', 255);

        // World count (VarInt) — always 1
        $data .= VarInt::write(1);

        // Dimension names — array of strings
        $data .= StringHandler::write("minecraft:overworld");

        // World name (String) — must match one from dimension names
        $data .= StringHandler::write("minecraft:overworld");

        // Hashed seed (long, 64-bit, big-endian)
        $data .= pack('J', 0); // Minecraft не валидирует сид строго

        // Max players (VarInt)
        $data .= VarInt::write(20);

        // View distance (VarInt)
        $data .= VarInt::write(10);

        // Simulation distance (VarInt)
        $data .= VarInt::write(10);

        // Reduced debug info (bool)
        $data .= pack('c', 0);

        // Enable respawn screen (bool)
        $data .= pack('c', 1);

        // Is debug (bool)
        $data .= pack('c', 0);

        // Is flat (bool) — ❌ REMOVED in 1.20+! DO NOT SEND!
        // Этот поле удалено в 1.20+ — его отправка ломает парсер клиента (DecoderException)!
        // Поэтому здесь ОСТАНАВЛИВАЕМСЯ — больше полей нет!

        return $data;
    }

    public function createPlayerPosition(int $clientId): string {
        $spawn = $this->server->getConfig()->getWorld()['spawn'];

        $data = '';

        // X, Y, Z - big-endian double
        $data .= pack('E', $spawn['x']); // X
        $data .= pack('E', $spawn['y']); // Y
        $data .= pack('E', $spawn['z']); // Z

        // Yaw, Pitch - big-endian float
        $data .= pack('G', 0.0); // Yaw
        $data .= pack('G', 0.0); // Pitch

        // Flags (0x00 = absolute)
        $data .= pack('c', 0x00);

        // Teleport ID (VarInt)
        $data .= VarInt::write(1);

        return $data;
    }

    public function createPlayerAbilities(int $clientId): string {
        $data = '';

        // Flags (byte) — 0x09 = invulnerable(0x01) | allow flying(0x08)
        $data .= pack('c', 0x09);

        // Fly speed and Walk speed - big-endian float
        $data .= pack('G', 0.05); // Fly speed
        $data .= pack('G', 0.1);  // Walk speed

        // Has fly speed: false / Has walk speed: false (1.20.1)
        $data .= pack('c', 0);
        $data .= pack('c', 0);

        return $data;
    }

    public function createKeepAlive(int $clientId): string {
        $keepAliveId = time();
        // Big-endian long (64 bits)
        return pack('J', $keepAliveId);
    }

    public function createStatusResponse(int $clientId): string {
        $status = [
            "version" => [
                "name" => "1.20.1",
                "protocol" => $this->server->getProtocolVersion()
            ],
            "players" => [
                "max" => 20,
                "online" => $this->server->getClientCount(),
                "sample" => [
                    ["name" => "HubPlayer1", "id" => UUIDGenerator::generate("HubPlayer1")],
                    ["name" => "HubPlayer2", "id" => UUIDGenerator::generate("HubPlayer2")]
                ]
            ],
            "description" => [
                "text" => "§a✓ PHP Hub Server §7| §fMinecraft 1.20.1\n§eДобро пожаловать!"
            ],
            "favicon" => "",
            "enforcesSecureChat" => false,
            "previewsChat" => false
        ];

        $json = json_encode($status, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        return StringHandler::write($json);
    }

    public function createFeatureFlags(): string {
        // Empty feature flags list
        return VarInt::write(0);
    }

    public function createPlayerInfo(int $clientId, string $username, string $uuid): string {
        if ($uuid === '') {
            $uuid = UUIDGenerator::generateOffline($username);
        }

        $data = '';

        // Number of players (VarInt) — 1.20.1 format: count first, no action byte
        $data .= VarInt::write(1);

        // UUID (16 raw bytes)
        $data .= UUIDGenerator::toBinary($uuid);

        // Username
        $data .= StringHandler::write($username);

        // Number of properties: 0
        $data .= VarInt::write(0);

        // Gamemode: 0 (Survival)
        $data .= VarInt::write(0);

        // Ping: 0
        $data .= VarInt::write(0);

        // Has display name: false
        $data .= pack('c', 0);

        return $data;
    }

    public function createSpawnPosition(int $clientId): string {
        $data = '';

        // Position (big-endian integers)
        $data .= pack('N', 0); // X
        $data .= pack('N', 64); // Y
        $data .= pack('N', 0); // Z

        // Angle (big-endian float)
        $data .= pack('G', 0.0);

        return $data;
    }
}

class ConnectionManager {
    private MinecraftServer $server;
    private array $connections = [];

    public function __construct(MinecraftServer $server) {
        $this->server = $server;
    }

    public function acceptNewConnection($socket): void {
        if ($socket !== false) {
            stream_set_blocking($socket, false);
            stream_set_timeout($socket, 30);

            $clientId = (int)$socket;
            $this->connections[$clientId] = [
                'socket' => $socket,
                'buffer' => '',
                'state' => 'handshake',
                'username' => '',
                'uuid' => '',
                'last_activity' => time(),
                'last_keep_alive' => time(),
                'keep_alive_id' => null,
                'connected' => true
            ];

            PacketLogger::logConnection("Новое подключение (ID: $clientId)");
        }
    }

    public function processConnections(): void {
        foreach ($this->connections as $clientId => &$connection) {
            if (!$connection['connected']) {
                continue;
            }

            // Timeout check
            if (time() - $connection['last_activity'] > 30) {
                PacketLogger::logConnection("Таймаут соединения для клиента $clientId");
                $this->closeConnection($clientId);
                continue;
            }

            $client = $connection['socket'];

            if (feof($client)) {
                PacketLogger::logConnection("Соединение разорвано (EOF) для клиента $clientId");
                $this->closeConnection($clientId);
                continue;
            }

            // Read data
            $data = @fread($client, 8192);
            if ($data === false || $data === '') {
                if (connection_aborted() || feof($client)) {
                    PacketLogger::logConnection("Соединение разорвано для клиента $clientId");
                    $this->closeConnection($clientId);
                }
                continue;
            }

            $connection['buffer'] .= $data;
            $connection['last_activity'] = time();
            $this->server->processBuffer($clientId, $connection['buffer']);
        }
    }

    public function closeConnection(int $clientId): void {
        if (isset($this->connections[$clientId])) {
            $connection = $this->connections[$clientId];
            $client = $connection['socket'];

            if (is_resource($client)) {
                @fclose($client);
            }

            unset($this->connections[$clientId]);
            PacketLogger::logConnection("Клиент $clientId отключен");
        }
    }

    public function sendPacket(int $clientId, int $packetId, string $data): bool {
        if (!isset($this->connections[$clientId]) || !$this->connections[$clientId]['connected']) {
            PacketLogger::logError("Клиент $clientId не подключен, пропуск отправки пакета 0x" . dechex($packetId));
            return false;
        }

        $client = $this->connections[$clientId]['socket'];

        if (!is_resource($client) || feof($client)) {
            PacketLogger::logConnection("Соединение разорвано перед отправкой пакета 0x" . dechex($packetId) . " клиенту $clientId");
            $this->closeConnection($clientId);
            return false;
        }

        $packetIdVarInt = VarInt::write($packetId);
        $packetData = $packetIdVarInt . $data;
        $packetLength = VarInt::write(strlen($packetData));
        $fullPacket = $packetLength . $packetData;

        PacketLogger::logPacket('⬅️ SENT', $clientId, $packetId, $fullPacket);

        $bytesWritten = @fwrite($client, $fullPacket);

        if ($bytesWritten === false || $bytesWritten !== strlen($fullPacket)) {
            PacketLogger::logError("Ошибка отправки пакета ID: 0x" . dechex($packetId) . " клиенту $clientId (написано $bytesWritten из " . strlen($fullPacket) . ")");

            if (feof($client)) {
                PacketLogger::logConnection("Соединение разорвано после неудачной отправки клиенту $clientId");
                $this->closeConnection($clientId);
            }

            return false;
        }

        $this->connections[$clientId]['last_activity'] = time();
        return true;
    }

    public function isClientConnected(int $clientId): bool {
        return isset($this->connections[$clientId]) &&
            $this->connections[$clientId]['connected'] &&
            is_resource($this->connections[$clientId]['socket']) &&
            !feof($this->connections[$clientId]['socket']);
    }

    public function getConnectionCount(): int {
        return count(array_filter($this->connections, fn($conn) => $conn['connected']));
    }

    public function getConnectionState(int $clientId): string {
        return $this->connections[$clientId]['state'] ?? 'handshake';
    }

    public function setConnectionState(int $clientId, string $state): void {
        if (isset($this->connections[$clientId])) {
            $this->connections[$clientId]['state'] = $state;
        }
    }

    public function setConnectionUsername(int $clientId, string $username): void {
        if (isset($this->connections[$clientId])) {
            $this->connections[$clientId]['username'] = $username;
        }
    }

    public function setConnectionUUID(int $clientId, string $uuid): void {
        if (isset($this->connections[$clientId])) {
            $this->connections[$clientId]['uuid'] = $uuid;
        }
    }

    public function getConnections(): array {
        return $this->connections;
    }
}

interface PacketHandler {
    public function handle(int $clientId, string $packetData): void;
}

class HandshakeHandler implements PacketHandler {
    private MinecraftServer $server;

    public function __construct(MinecraftServer $server) {
        $this->server = $server;
    }

    public function handle(int $clientId, string $packetData): void {
        $offset = 0;
        $packetId = VarInt::read($packetData, $offset);

        if ($packetId === 0x00) {
            $protocolVersion = VarInt::read($packetData, $offset);
            $serverAddress = StringHandler::read($packetData, $offset);
            $serverPort = unpack('n', substr($packetData, $offset, 2))[1];
            $offset += 2;
            $nextState = VarInt::read($packetData, $offset);

            PacketLogger::logInfo("Handshake: protocol=$protocolVersion, nextState=$nextState");
            PacketLogger::logInfo("Адрес: $serverAddress, порт: $serverPort");

            if ($nextState === 1) {
                $this->server->setConnectionState($clientId, 'status');
            } elseif ($nextState === 2) {
                $this->server->setConnectionState($clientId, 'login');
            }
        }
    }
}

class StatusHandler implements PacketHandler {
    private MinecraftServer $server;
    private PacketFactory $packetFactory;

    public function __construct(MinecraftServer $server, PacketFactory $packetFactory) {
        $this->server = $server;
        $this->packetFactory = $packetFactory;
    }

    public function handle(int $clientId, string $packetData): void {
        $offset = 0;
        $packetId = VarInt::read($packetData, $offset);

        if ($packetId === 0x00) {
            $response = $this->packetFactory->createStatusResponse($clientId);
            $this->server->sendPacket($clientId, 0x00, $response);
            PacketLogger::logInfo("Отправлен статус сервера клиенту $clientId");
        } elseif ($packetId === 0x01) {
            $timestamp = unpack('J', substr($packetData, $offset, 8))[1] ?? time();
            $response = pack('J', $timestamp);
            $this->server->sendPacket($clientId, 0x01, $response);
            PacketLogger::logInfo("Ping/Pong завершен для клиента $clientId");
        }
    }
}

class LoginHandler implements PacketHandler {
    private MinecraftServer $server;
    private PacketFactory $packetFactory;

    public function __construct(MinecraftServer $server, PacketFactory $packetFactory) {
        $this->server = $server;
        $this->packetFactory = $packetFactory;
    }

    public function handle(int $clientId, string $packetData): void {
        $offset = 0;
        $packetId = VarInt::read($packetData, $offset);

        if ($packetId === 0x00) { // Login Start
            // 1. Читаем имя игрока (VarInt длина + строка)
            $username = StringHandler::read($packetData, $offset);
            $this->server->setConnectionUsername($clientId, $username);
            PacketLogger::logInfo("📝 Login Start: username={$username} (клиент {$clientId})");

            // 2. В Minecraft 1.19.3+ (включая 1.20.1) сразу после имени идет UUID (ровно 16 байт).
            // Никакого "Has Signature Data" в этом месте нет — не читаем сигнатуры для offline-mode.
            $remainingBytes = strlen($packetData) - $offset;
            $uuid = null;

            if ($remainingBytes >= 16) {
                // Читаем ровно 16 байт UUID, которые прислал клиент
                $uuidBytes = substr($packetData, $offset, 16);
                $uuid = UUIDGenerator::fromBytes($uuidBytes);
                PacketLogger::logInfo("🎯 Используем UUID клиента: {$uuid}");
            } else {
                // Fallback только если клиент прислал битый пакет (например, очень старый лаунчер)
                $uuid = UUIDGenerator::generateOffline($username);
                PacketLogger::logInfo("⚠️ Fallback: Сгенерирован offline UUID: {$uuid}");
            }

            $this->server->setConnectionUUID($clientId, $uuid);

            // 3. Сразу отправляем Login Success (0x02). Никаких задержек и никаких
            // Login Plugin Request — отправка любого пакета до Login Acknowledged ломает парсер клиента!
            $successPayload = UUIDGenerator::toBinary($uuid) . StringHandler::write($username);
            $this->server->sendPacket($clientId, 0x02, $successPayload);
            PacketLogger::logInfo("✅ Login Success (0x02) отправлен клиенту $clientId");

            // 4. Теперь мы ЖДЕМ, что клиент пришлет Login Acknowledged (0x03)
            PacketLogger::logInfo("⏳ Ожидаем Login Acknowledged (0x03) от клиента...");
        } elseif ($packetId === 0x03) { // Login Acknowledged
            PacketLogger::logInfo("✅ Получен Login Acknowledged (0x03). Переход в Configuration State.");
            $this->server->setConnectionState($clientId, 'configuration');
            $this->server->sendConfigurationPackets($clientId);
        } else {
            PacketLogger::logInfo("⚠️ Неожиданный пакет 0x" . dechex($packetId) . " в состоянии Login");
        }
    }
}

class ConfigurationHandler implements PacketHandler {
    private MinecraftServer $server;

    public function __construct(MinecraftServer $server) {
        $this->server = $server;
    }

    public function handle(int $clientId, string $packetData): void {
        $offset = 0;
        $packetId = VarInt::read($packetData, $offset);

        if ($packetId === 0x02) { // Acknowledge Finish Configuration (Client -> Server)
            PacketLogger::logInfo("✅ Configuration завершена. Переход в PLAY State. Спавним игрока...");
            $this->server->setConnectionState($clientId, 'play');
            $this->server->sendGamePackets($clientId);
        } else {
            PacketLogger::logInfo("📥 Игнорируем конфигурационный пакет 0x" . dechex($packetId));
        }
    }
}

class PlayHandler implements PacketHandler {
    private MinecraftServer $server;

    public function __construct(MinecraftServer $server) {
        $this->server = $server;
    }

    public function handle(int $clientId, string $packetData): void {
        $offset = 0;
        $packetId = VarInt::read($packetData, $offset);

        switch ($packetId) {
            case 0x12: // Keep Alive (Client -> Server, 1.20.1)
                $keepAliveId = unpack('J', substr($packetData, $offset, 8))[1] ?? 0;
                PacketLogger::logInfo("Keep Alive получен от клиента $clientId: $keepAliveId");
                break;

            case 0x1C: // Teleport Confirmation (Client -> Server, 1.20.1)
                $teleportId = VarInt::read($packetData, $offset);
                $expected = $this->server->getExpectedTeleportId($clientId);
                if ($expected !== null && $teleportId === $expected) {
                    $this->server->onTeleportConfirmed($clientId);
                    PacketLogger::logInfo("✅ Телепорт подтвержден клиентом $clientId (id=$teleportId). Позиция зафиксирована.");
                } else {
                    PacketLogger::logInfo("Teleport Confirm от клиента $clientId: id=$teleportId (ожидался $expected)");
                }
                break;

            case 0x0A: // Move and Look with State / Position (зависит от версии, просто логируем)
            case 0x0B: // Move with Rotation/State
            case 0x0C: // Move/Rotations variants
                PacketLogger::logDebug("Движение игрока (клиент $clientId), пакет 0x" . dechex($packetId));
                break;

            case 0x03: // Chat Preview / Sign edit и т.п. — пробуем прочитать как строку
                $remaining = strlen($packetData) - $offset;
                if ($remaining > 0) {
                    $message = StringHandler::read($packetData, $offset);
                    if ($message !== '') {
                        PacketLogger::logInfo("Чат/текст от клиента $clientId: $message");
                    }
                }
                break;
        }
    }
}

class PacketDispatcher {
    private array $handlers = [];

    public function registerHandler(string $state, PacketHandler $handler): void {
        $this->handlers[$state] = $handler;
    }

    public function dispatch(int $clientId, string $state, string $packetData): void {
        if (isset($this->handlers[$state])) {
            $this->handlers[$state]->handle($clientId, $packetData);
        } else {
            PacketLogger::logError("No handler for state: $state");
        }
    }
}

class ServerConfig {
    private array $config;

    public function __construct() {
        $this->config = [
            'host' => '0.0.0.0',
            'port' => 25565,
            'protocol_version' => 763, // 1.20.1
            'server_name' => "PHP Minecraft Hub Server",
            'motd' => "§a✓ PHP Hub Server §7| §fMinecraft 1.20.1\n§eДобро пожаловать!",
            'max_players' => 20,
            'view_distance' => 10,
            'simulation_distance' => 10,
            'world' => WorldGenerator::generateFlatWorld()
        ];
    }

    public function get(string $key, $default = null) {
        return $this->config[$key] ?? $default;
    }

    public function set(string $key, $value): void {
        $this->config[$key] = $value;
    }

    public function getMotd(): string {
        return $this->config['motd'];
    }

    public function getWorld(): array {
        return $this->config['world'];
    }
}

class MinecraftServer {
    private $socket;
    private bool $running = true;
    private ServerConfig $config;
    private ConnectionManager $connectionManager;
    private PacketFactory $packetFactory;
    private PacketDispatcher $packetDispatcher;

    public function __construct(ServerConfig $config) {
        $this->config = $config;
        $this->packetFactory = new PacketFactory($this);
        $this->connectionManager = new ConnectionManager($this);
        $this->packetDispatcher = new PacketDispatcher();

        // Register handlers
        $this->packetDispatcher->registerHandler('handshake', new HandshakeHandler($this));
        $this->packetDispatcher->registerHandler('status', new StatusHandler($this, $this->packetFactory));
        $this->packetDispatcher->registerHandler('login', new LoginHandler($this, $this->packetFactory));
        $this->packetDispatcher->registerHandler('configuration', new ConfigurationHandler($this));
        $this->packetDispatcher->registerHandler('play', new PlayHandler($this));

        PacketLogger::clearLog();
    }

    public function getConfig(): ServerConfig {
        return $this->config;
    }

    public function start(): void {
        $address = "tcp://{$this->config->get('host')}:{$this->config->get('port')}";
        $this->socket = @stream_socket_server($address, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);

        if (!$this->socket) {
            throw new Exception("Не удалось создать сервер: $errstr ($errno)");
        }

        stream_set_blocking($this->socket, false);

        PacketLogger::logInfo("Minecraft 1.20.1 хаб-сервер слушает на {$this->config->get('host')}:{$this->config->get('port')}");
        PacketLogger::logInfo("Протокол: {$this->config->get('protocol_version')}");
        PacketLogger::logInfo("MOTD: {$this->config->getMotd()}");

        $this->mainLoop();
    }

    private function mainLoop(): void {
        while ($this->running) {
            $this->acceptConnections();
            $this->processConnections();
            $this->checkKeepAlives();
            usleep(10000); // 10ms
        }
    }

    private function acceptConnections(): void {
        $client = @stream_socket_accept($this->socket, 0);
        $this->connectionManager->acceptNewConnection($client);
    }

    private function processConnections(): void {
        $this->connectionManager->processConnections();
    }

    private function checkKeepAlives(): void {
        $currentTime = time();
        foreach ($this->connectionManager->getConnections() as $clientId => $connection) {
            if ($connection['state'] === 'play' && $currentTime - $connection['last_activity'] > 15) {
                $this->sendKeepAlive($clientId);
            }
        }
    }

    public function processBuffer(int $clientId, string &$buffer): void {
        while (strlen($buffer) > 0) {
            $offset = 0;
            $packetLength = VarInt::read($buffer, $offset);

            if ($packetLength === null || strlen($buffer) < $offset + $packetLength) {
                break;
            }

            $packetData = substr($buffer, $offset, $packetLength);
            $totalLength = $offset + $packetLength;
            $buffer = substr($buffer, $totalLength);

            $this->handlePacket($clientId, $packetData);
        }
    }

    private function handlePacket(int $clientId, string $packetData): void {
        $offset = 0;
        $packetId = VarInt::read($packetData, $offset);

        if ($packetId !== null) {
            PacketLogger::logInfo("Получен пакет 0x" . dechex($packetId) . " от клиента $clientId");
            PacketLogger::logPacket('➡️ RECEIVED', $clientId, $packetId, $packetData);

            $state = $this->connectionManager->getConnectionState($clientId);
            $this->packetDispatcher->dispatch($clientId, $state, $packetData);
        }
    }

    /**
     * Configuration State (1.20.1): отправляем минимальный набор пакетов и ждем
     * Acknowledge Finish Configuration (0x02) от клиента.
     */
    public function sendConfigurationPackets(int $clientId): void {
        PacketLogger::logInfo("📦 Отправка Configuration пакетов клиенту $clientId...");

        if (!$this->connectionManager->isClientConnected($clientId)) {
            PacketLogger::logError("Соединение разорвано перед отправкой Configuration пакетов");
            return;
        }

        // 1. Plugin Message — Minecraft Brand ("brand" канал, payload = VarInt строка "minecraft")
        $brandPayload = StringHandler::write('minecraft');
        $brandData = StringHandler::write('minecraft:brand') . $brandPayload;
        $this->sendPacket($clientId, 0x00, $brandData); // CB_CONFIG_PLUGIN_MESSAGE
        PacketLogger::logInfo("⬅️ Plugin Message (brand, 0x00) отправлен");

        // 2. Known Packs (0x0D) — один пак "minecraft/core"
        $knownPacksData = '';
        $knownPacksData .= VarInt::write(1);                     // Количество паков
        $knownPacksData .= StringHandler::write('minecraft');    // Namespace
        $knownPacksData .= StringHandler::write('core');         // ID
        $knownPacksData .= StringHandler::write('1.20.1');       // Version
        $this->sendPacket($clientId, 0x0D, $knownPacksData);     // CB_CONFIG_KNOWN_PACKS
        PacketLogger::logInfo("⬅️ Known Packs (0x0D) отправлен");

        // 3. Finish Configuration (0x02) — пустой payload.
        // Клиент ответит Acknowledge Finish Configuration (0x02), после чего мы перейдем в Play.
        $this->sendPacket($clientId, 0x02, '');                  // CB_CONFIG_FINISH_CONFIGURATION
        PacketLogger::logInfo("⬅️ Finish Configuration (0x02) отправлен");

        PacketLogger::logInfo("⏳ Ожидаем Acknowledge Finish Configuration (0x02) от клиента...");
    }

    /**
     * Play State: спавним игрока (Join Game + позиционные и служебные пакеты).
     * Имя и UUID берем из состояния соединения (получены на этапе Login Start).
     */
    public function sendGamePackets(int $clientId): void {
        $conn = $this->connectionManager->getConnections()[$clientId] ?? null;
        $username = $conn['username'] ?? 'Player';
        $uuid = $conn['uuid'] ?? '';

        PacketLogger::logInfo("Начинаю отправку игровых пакетов клиенту $clientId...");

        if (!$this->connectionManager->isClientConnected($clientId)) {
            PacketLogger::logError("Соединение разорвано перед началом отправки игровых пакетов");
            return;
        }

        try {
            PacketLogger::logInfo("Отправка игровых пакетов для Minecraft 1.20.1...");

            // 1. Join Game (0x26) — формат 1.20.1 (без NBT-реестра, без is_flat!)
            $joinGameData = $this->packetFactory->createJoinGame($clientId, $uuid);
            $this->sendPacket($clientId, 0x26, $joinGameData);
            PacketLogger::logInfo("Join Game (0x26) отправлен клиенту $clientId");

            // 2. Player Info (0x34)
            $playerInfoData = $this->packetFactory->createPlayerInfo($clientId, $username, $uuid);
            $this->sendPacket($clientId, 0x34, $playerInfoData);
            PacketLogger::logInfo("Player Info (0x34) отправлен клиенту $clientId");

            // 3. Update View Position (0x49)
            $viewPosData = pack('N', 0) . pack('N', 0);
            $this->sendPacket($clientId, 0x49, $viewPosData);
            PacketLogger::logInfo("Update View Position (0x49) отправлен клиенту $clientId");

            // 4. Update View Distance (0x4B)
            $viewDistData = VarInt::write(10);
            $this->sendPacket($clientId, 0x4B, $viewDistData);
            PacketLogger::logInfo("Update View Distance (0x4B) отправлен клиенту $clientId");

            // 5. Spawn Position (0x4D в 1.20.1)
            $spawnPositionData = $this->packetFactory->createSpawnPosition($clientId);
            $this->sendPacket($clientId, 0x4D, $spawnPositionData);
            PacketLogger::logInfo("Spawn Position (0x4D) отправлен клиенту $clientId");

            // 6. Player Abilities (0x1E)
            $abilitiesData = $this->packetFactory->createPlayerAbilities($clientId);
            $this->sendPacket($clientId, 0x1E, $abilitiesData);
            PacketLogger::logInfo("Player Abilities (0x1E) отправлен клиенту $clientId");

            // 7. Chunk Data (0x1D) + свет (0x22) — игрок должен увидеть блоки под ногами
            $chunkData = WorldGenerator::generateFlatChunk();
            $this->sendPacket($clientId, 0x1D, $chunkData);
            $lightData = "\x00" . VarInt::write(1) . VarInt::write(0) . VarInt::write(1) . VarInt::write(2048) . str_repeat("\xff", 2048);
            $this->sendPacket($clientId, 0x22, $lightData);
            PacketLogger::logInfo("Chunk Data (0x1D) + Map Chunk Light (0x22) отправлены клиенту $clientId");

            // 8. Synchronize Player Position (0x3A) — телепорт игрока, чтобы он появился в мире.
            // ВАЖНО: после Join Game клиент игнорирует позиции до получения этого пакета.
            $positionData = $this->packetFactory->createPlayerPosition($clientId);
            $this->sendPacket($clientId, 0x3A, $positionData);
            $this->connectionManager->setTeleportAck($clientId, 1);
            PacketLogger::logInfo("Player Position (0x3A) отправлен клиенту $clientId. Игрок должен появиться в мире!");

            // 9. Keep Alive (0x33 в 1.20.1)
            $keepAliveData = $this->packetFactory->createKeepAlive($clientId);
            $this->connectionManager->setKeepAliveId($clientId, $keepAliveData);
            $this->sendPacket($clientId, 0x33, $keepAliveData);
            PacketLogger::logInfo("Keep Alive (0x33) отправлен клиенту $clientId");

            $world = $this->config->getWorld();
            PacketLogger::logInfo("🌍 Мир загружен для клиента $clientId, игрок на позиции {$world['spawn']['x']}, {$world['spawn']['y']}, {$world['spawn']['z']}");
            PacketLogger::logInfo("🎮 Игрок $username успешно вошел в игру (клиент $clientId)");

        } catch (Exception $e) {
            PacketLogger::logError("Ошибка при отправке игровых пакетов: " . $e->getMessage());
            $this->connectionManager->closeConnection($clientId);
        }
    }

    private function sendKeepAlive(int $clientId): void {
        $keepAliveData = $this->packetFactory->createKeepAlive($clientId);
        $this->connectionManager->setKeepAliveId($clientId, $keepAliveData);
        $this->sendPacket($clientId, 0x33, $keepAliveData);
        PacketLogger::logInfo("Keep Alive (0x33) отправлен клиенту $clientId");
    }

    public function sendPacket(int $clientId, int $packetId, string $data): bool {
        return $this->connectionManager->sendPacket($clientId, $packetId, $data);
    }

    public function getProtocolVersion(): int {
        return $this->config->get('protocol_version');
    }

    public function getClientCount(): int {
        return $this->connectionManager->getConnectionCount();
    }

    public function setConnectionState(int $clientId, string $state): void {
        $this->connectionManager->setConnectionState($clientId, $state);
    }

    public function setConnectionUsername(int $clientId, string $username): void {
        $this->connectionManager->setConnectionUsername($clientId, $username);
    }

    public function setConnectionUUID(int $clientId, string $uuid): void {
        $this->connectionManager->setConnectionUUID($clientId, $uuid);
    }

    public function __destruct() {
        $this->running = false;

        if ($this->socket && is_resource($this->socket)) {
            fclose($this->socket);
        }

        foreach ($this->connectionManager->getConnections() as $clientId => $connection) {
            $this->connectionManager->closeConnection($clientId);
        }
    }
}

// Extension check
$requiredExtensions = ['json'];
foreach ($requiredExtensions as $ext) {
    if (!extension_loaded($ext)) {
        PacketLogger::logError("Расширение $ext не загружено. Требуется для работы сервера.");
        exit(1);
    }
}

// Signal handling
if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    register_shutdown_function(function() {
        PacketLogger::logInfo("Сервер остановлен");
    });
} else {
    if (function_exists('pcntl_signal')) {
        declare(ticks=1);
        pcntl_signal(SIGINT, function() {
            PacketLogger::logInfo("Получен сигнал SIGINT, остановка сервера...");
            exit(0);
        });
    }
}

// Server startup
try {
    PacketLogger::logInfo("🚀 Запуск Minecraft хаб-сервера...");
    PacketLogger::logInfo("🔧 PHP версия: " . phpversion());
    PacketLogger::logInfo("🌍 Операционная система: " . PHP_OS);
    PacketLogger::logInfo("🕐 Текущее время: " . date('Y-m-d H:i:s'));

    $config = new ServerConfig();
    $server = new MinecraftServer($config);
    $server->start();
} catch (Exception $e) {
    PacketLogger::logError("Ошибка: " . $e->getMessage());
    exit(1);
}