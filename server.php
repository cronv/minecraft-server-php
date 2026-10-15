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
    public static function generate(string $username): string {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%012x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            crc32($username . time())
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

        // Full chunk flag
        $chunkData .= pack('c', 1);

        // Heightmaps
        $heightmaps = [
            'MOTION_BLOCKING' => ['0' => []],
            'WORLD_SURFACE' => ['0' => []]
        ];
        $heightmapsJson = json_encode($heightmaps, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $chunkData .= StringHandler::write($heightmapsJson);

        // Biomes (1024 entries)
        $chunkData .= VarInt::write(1024);
        for ($i = 0; $i < 1024; $i++) {
            $chunkData .= VarInt::write(1); // Plains biome
        }

        // Chunk sections
        $chunkData .= self::generateChunkSections();

        // Block entities count
        $chunkData .= VarInt::write(0);

        return $chunkData;
    }

    private static function generateChunkSections(): string {
        $sections = '';

        // Sections count
        $sections .= VarInt::write(1); // One section

        // Section Y position
        $sections .= VarInt::write(0);

        // Block states palette size
        $sections .= VarInt::write(2); // 2 block types: air and stone

        // Palette entries
        $sections .= StringHandler::write("minecraft:air");
        $sections .= StringHandler::write("minecraft:stone");

        // Block states data
        $sections .= VarInt::write(1); // Bits per block
        $sections .= VarInt::write(1024); // Data array length

        // Block data (all air)
        $sections .= str_repeat("\x00", 512); // 1024 bits = 512 bytes

        // Biomes data
        $sections .= VarInt::write(0); // Bits per biome
        $sections .= VarInt::write(0); // Data array length

        // Block light
        $sections .= "\x00";

        // Sky light
        $sections .= "\x00";

        return $sections;
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

        // Hardcore (bool)
        $data .= pack('c', 0); // false

        // Gamemode (unsigned byte) — 0 = Survival
        $data .= pack('C', 0);

        // Previous gamemode (unsigned byte) — 255 = no previous
        $data .= pack('C', 255);

        // World count (VarInt) — always 1
        $data .= VarInt::write(1);

        // World names — array of strings
        $data .= StringHandler::write("minecraft:overworld");

        // Dimension registry (NBT Tag Compound)
        $registryNBT = $this->createDimensionRegistryNBT();
        $data .= $registryNBT;

        // Dimension type (String) — must match one from registry
        $data .= StringHandler::write("minecraft:overworld");

        // World name (String) — must match one from world_names
        $data .= StringHandler::write("minecraft:overworld");

        // Hashed seed (long, 64-bit, big-endian)
        $seed = crc32("default_seed" . time()); // 32-bit, but we need 64-bit
        // Use 0 for seed if you don't care — Minecraft doesn't validate it strictly
        $data .= pack('J', 0); // 64-bit signed long, big-endian

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

        // Is debug (bool) — 1.20.1: no longer used, but still sent
        $data .= pack('c', 0);

        // Is flat (bool) — ❌ REMOVED in 1.20.1! DO NOT SEND!
        // This field was removed in 1.20+ — sending it causes client crash!
        // So we STOP HERE — NO MORE FIELDS!

        return $data;
    }

    private function createDimensionRegistryNBT(): string {
        $writer = new NBTWriter();
        $writer->reset();

        // Root compound
        $writer->writeByte(10); // TAG_Compound
        $writer->writeString(''); // empty name

        // minecraft:dimension_type
        $writer->writeByte(10); // TAG_Compound
        $writer->writeString('minecraft:dimension_type');

        // List of dimension types (TAG_List of TAG_Compound)
        $writer->writeByte(9); // TAG_List
        $writer->writeString('type');
        $writer->writeByte(10); // TAG_Compound (element type)
        $writer->writeInt(1); // 1 element

        // First (and only) dimension type
        $writer->writeByte(10); // TAG_Compound
        $writer->writeString(''); // empty name

        // name
        $writer->writeByte(8); // TAG_String
        $writer->writeString('minecraft:overworld');

        // id
        $writer->writeByte(3); // TAG_Int
        $writer->writeInt(0);

        // element
        $writer->writeByte(10); // TAG_Compound
        $writer->writeString('element');

        // ultrawarm
        $writer->writeByte(1); // TAG_Byte
        $writer->writeString('ultrawarm');
        $writer->writeByte(0);

        // natural
        $writer->writeByte(1); // TAG_Byte
        $writer->writeString('natural');
        $writer->writeByte(1);

        // coordinate_scale
        $writer->writeByte(6); // TAG_Double
        $writer->writeString('coordinate_scale');
        $writer->buffer .= pack('d', 1.0); // native double, little-endian → but NBT expects big-endian

        // ambient_light
        $writer->writeByte(6); // TAG_Double
        $writer->writeString('ambient_light');
        $writer->buffer .= pack('d', 0.0);

        // has_ceiling
        $writer->writeByte(1); // TAG_Byte
        $writer->writeString('has_ceiling');
        $writer->writeByte(0);

        // has_skylight
        $writer->writeByte(1); // TAG_Byte
        $writer->writeString('has_skylight');
        $writer->writeByte(1);

        // piglin_safe
        $writer->writeByte(1); // TAG_Byte
        $writer->writeString('piglin_safe');
        $writer->writeByte(0);

        // bed_works
        $writer->writeByte(1); // TAG_Byte
        $writer->writeString('bed_works');
        $writer->writeByte(1);

        // respawn_anchor_works
        $writer->writeByte(1); // TAG_Byte
        $writer->writeString('respawn_anchor_works');
        $writer->writeByte(0);

        // has_raids
        $writer->writeByte(1); // TAG_Byte
        $writer->writeString('has_raids');
        $writer->writeByte(1);

        // min_y
        $writer->writeByte(3); // TAG_Int
        $writer->writeString('min_y');
        $writer->writeInt(0);

        // height
        $writer->writeByte(3); // TAG_Int
        $writer->writeString('height');
        $writer->writeInt(256);

        // logical_height
        $writer->writeByte(3); // TAG_Int
        $writer->writeString('logical_height');
        $writer->writeInt(256);

        // infiniburn
        $writer->writeByte(8); // TAG_String
        $writer->writeString('infiniburn');
        $writer->writeString('#minecraft:infiniburn_overworld');

        // effects
        $writer->writeByte(8); // TAG_String
        $writer->writeString('effects');
        $writer->writeString('minecraft:overworld');

        // monster_spawn_light_level
        $writer->writeByte(3); // TAG_Int
        $writer->writeString('monster_spawn_light_level');
        $writer->writeInt(7);

        // monster_spawn_block_light_limit
        $writer->writeByte(3); // TAG_Int
        $writer->writeString('monster_spawn_block_light_limit');
        $writer->writeInt(0);

        // End element compound
        $writer->writeByte(0);

        // End dimension type compound
        $writer->writeByte(0);

        // End minecraft:dimension_type
        $writer->writeByte(0);

        // minecraft:worldgen/biome — empty
        $writer->writeByte(10); // TAG_Compound
        $writer->writeString('minecraft:worldgen/biome');
        $writer->writeByte(9); // TAG_List
        $writer->writeString('type');
        $writer->writeByte(10); // TAG_Compound
        $writer->writeInt(0); // 0 elements
        $writer->writeByte(0); // end list
        $writer->writeByte(0); // end compound

        // End root compound
        $writer->writeByte(0);

        return $writer->getBuffer();
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

        // Flags: 0x0B = 0b1011 = invulnerable, flying, allow_flying, creative mode
        $data .= pack('c', 0x0B);

        // Fly speed and Walk speed - big-endian float
        $data .= pack('G', 0.05); // Fly speed
        $data .= pack('G', 0.1);  // Walk speed

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
        $data = '';

        // Action: 0x00 = Add Player
        $data .= VarInt::write(0);

        // Number of players: 1
        $data .= VarInt::write(1);

        // UUID
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

        if ($packetId === 0x00) {
            $username = StringHandler::read($packetData, $offset);
            $this->server->setConnectionUsername($clientId, $username);

            PacketLogger::logInfo("Login Start: $username (клиент $clientId)");

            $uuid = UUIDGenerator::generate($username);
            $this->server->setConnectionUUID($clientId, $uuid);
            PacketLogger::logInfo("Сгенерирован UUID: $uuid для клиента $clientId");

            $uuidBytes = UUIDGenerator::toBinary($uuid);
            $usernameBytes = StringHandler::write($username);

            $this->server->sendPacket($clientId, 0x02, $uuidBytes . $usernameBytes);
            PacketLogger::logInfo("Login Success отправлен клиенту $clientId");

            $this->server->setConnectionState($clientId, 'play');
            $this->server->sendGamePackets($clientId, $username, $uuid);
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
            case 0x1F: // Keep Alive
                $keepAliveId = unpack('J', substr($packetData, $offset, 8))[1] ?? 0;
                $response = pack('J', $keepAliveId);
                $this->server->sendPacket($clientId, 0x1F, $response);
                PacketLogger::logInfo("Keep Alive получен от клиента $clientId: $keepAliveId");
                break;

            case 0x03: // Chat Message
                $messageLength = VarInt::read($packetData, $offset);
                $message = substr($packetData, $offset, $messageLength);
                PacketLogger::logInfo("Чат от клиента $clientId: $message");
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

    public function sendGamePackets(int $clientId, string $username, string $uuid): void {
        PacketLogger::logInfo("Начинаю отправку игровых пакетов клиенту $clientId...");

        if (!$this->connectionManager->isClientConnected($clientId)) {
            PacketLogger::logError("Соединение разорвано перед началом отправки игровых пакетов");
            return;
        }

        try {
            PacketLogger::logInfo("Отправка игровых пакетов для Minecraft 1.20.1...");

            // 1. Feature Flags (0x08)
            $featureFlagsData = $this->packetFactory->createFeatureFlags();
            $this->sendPacket($clientId, 0x08, $featureFlagsData);
            PacketLogger::logInfo("Feature Flags (0x08) отправлен клиенту $clientId");
            $this->sleepWithCheck(50000, $clientId); // 50ms

            if (!$this->connectionManager->isClientConnected($clientId)) {
                PacketLogger::logError("Соединение разорвано перед отправкой Join Game");
                return;
            }

            // 2. Join Game (0x26) - ИСПРАВЛЕННЫЙ ФОРМАТ
            $joinGameData = $this->packetFactory->createJoinGame($clientId, $uuid);
            $this->sendPacket($clientId, 0x26, $joinGameData);
            PacketLogger::logInfo("Join Game (0x26) отправлен клиенту $clientId");
            $this->sleepWithCheck(50000, $clientId); // 50ms

            if (!$this->connectionManager->isClientConnected($clientId)) {
                PacketLogger::logError("Соединение разорвано перед отправкой Player Info");
                return;
            }

            // 3. Player Info (0x32)
            $playerInfoData = $this->packetFactory->createPlayerInfo($clientId, $username, $uuid);
            $this->sendPacket($clientId, 0x32, $playerInfoData);
            PacketLogger::logInfo("Player Info (0x32) отправлен клиенту $clientId");
            $this->sleepWithCheck(30000, $clientId); // 30ms

            if (!$this->connectionManager->isClientConnected($clientId)) {
                PacketLogger::logError("Соединение разорвано перед отправкой Tags");
                return;
            }

            // 4. Tags (0x5A)
            $nbtWriter = new NBTWriter();
            $tagsData = $nbtWriter->writeSimpleTags();
            $this->sendPacket($clientId, 0x5A, $tagsData);
            PacketLogger::logInfo("Tags (0x5A) отправлен клиенту $clientId");
            $this->sleepWithCheck(50000, $clientId); // 50ms

            if (!$this->connectionManager->isClientConnected($clientId)) {
                PacketLogger::logError("Соединение разорвано перед отправкой Spawn Position");
                return;
            }

            // 5. Spawn Position (0x41)
            $spawnPositionData = $this->packetFactory->createSpawnPosition($clientId);
            $this->sendPacket($clientId, 0x41, $spawnPositionData);
            PacketLogger::logInfo("Spawn Position (0x41) отправлен клиенту $clientId");
            $this->sleepWithCheck(30000, $clientId); // 30ms

            if (!$this->connectionManager->isClientConnected($clientId)) {
                PacketLogger::logError("Соединение разорвано перед отправкой Chunk Data");
                return;
            }

            // 6. Chunk Data (0x22)
            $chunkData = WorldGenerator::generateFlatChunk();
            $this->sendPacket($clientId, 0x22, $chunkData);
            PacketLogger::logInfo("Chunk Data (0x22) отправлен клиенту $clientId");
            $this->sleepWithCheck(50000, $clientId); // 50ms

            if (!$this->connectionManager->isClientConnected($clientId)) {
                PacketLogger::logError("Соединение разорвано перед отправкой Player Position");
                return;
            }

            // 7. Player Position and Look (0x37)
            $positionData = $this->packetFactory->createPlayerPosition($clientId);
            $this->sendPacket($clientId, 0x37, $positionData);
            PacketLogger::logInfo("Player Position and Look (0x37) отправлен клиенту $clientId");
            $this->sleepWithCheck(30000, $clientId); // 30ms

            if (!$this->connectionManager->isClientConnected($clientId)) {
                PacketLogger::logError("Соединение разорвано перед отправкой Player Abilities");
                return;
            }

            // 8. Player Abilities (0x39)
            $abilitiesData = $this->packetFactory->createPlayerAbilities($clientId);
            $this->sendPacket($clientId, 0x39, $abilitiesData);
            PacketLogger::logInfo("Player Abilities (0x39) отправлен клиенту $clientId");
            $this->sleepWithCheck(30000, $clientId); // 30ms

            if (!$this->connectionManager->isClientConnected($clientId)) {
                PacketLogger::logError("Соединение разорвано перед отправкой Keep Alive");
                return;
            }

            // 9. Keep Alive (0x1F)
            $keepAliveData = $this->packetFactory->createKeepAlive($clientId);
            $this->sendPacket($clientId, 0x1F, $keepAliveData);
            PacketLogger::logInfo("Keep Alive (0x1F) отправлен клиенту $clientId");

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
        $this->sendPacket($clientId, 0x1F, $keepAliveData);
        PacketLogger::logInfo("Keep Alive отправлен клиенту $clientId");
    }

    public function sendPacket(int $clientId, int $packetId, string $data): bool {
        return $this->connectionManager->sendPacket($clientId, $packetId, $data);
    }

    private function sleepWithCheck(int $microseconds, int $clientId): void {
        $start = microtime(true);
        $sleepTime = $microseconds / 1000000;

        while ((microtime(true) - $start) < $sleepTime) {
            if (!$this->connectionManager->isClientConnected($clientId)) {
                PacketLogger::logDebug("Прерывание сна - клиент $clientId отключился");
                break;
            }
            usleep(1000); // 1ms
        }
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