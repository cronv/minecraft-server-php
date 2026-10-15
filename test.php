<?php
declare(strict_types=1);

class MinecraftServer {
    private $socket;
    private $running = true;
    private $connections = [];

    // Конфигурация сервера
    private $config = [
        'host' => '0.0.0.0',
        'port' => 25565,
        'protocol' => 763, // 1.20.1
        'max_players' => 20,
        'motd' => "§aPHP Minecraft Server §7| §f1.20.1",
        'world' => [
            'name' => 'world',
            'seed' => 1234567890,
            'spawn' => ['x' => 0.5, 'y' => 64.0, 'z' => 0.5]
        ]
    ];

    public function __construct() {
        $this->log("🚀 Запуск Minecraft 1.20.1 сервера на PHP 8.2");
    }

    private function log(string $message): void {
        $timestamp = date('Y-m-d H:i:s');
        echo "[$timestamp] $message\n";
    }

    public function start(): void {
        $address = "tcp://{$this->config['host']}:{$this->config['port']}";
        $this->socket = stream_socket_server($address, $errno, $errstr);

        if (!$this->socket) {
            $this->log("❌ Ошибка создания сервера: $errstr ($errno)");
            exit(1);
        }

        stream_set_blocking($this->socket, false);

        $this->log("✅ Сервер запущен на {$this->config['host']}:{$this->config['port']}");
        $this->log("📡 Протокол: 1.20.1 (763)");
        $this->log("👥 Макс игроков: {$this->config['max_players']}");
        $this->log("🌍 Мир: {$this->config['world']['name']}");

        $this->mainLoop();
    }

    private function mainLoop(): void {
        while ($this->running) {
            $this->acceptConnections();
            $this->processConnections();
            usleep(10000); // 10ms
        }
    }

    private function acceptConnections(): void {
        $client = @stream_socket_accept($this->socket, 0);
        if ($client !== false) {
            stream_set_blocking($client, false);
            $clientId = (int)$client;

            $this->connections[$clientId] = [
                'socket' => $client,
                'buffer' => '',
                'state' => 'handshake',
                'compression' => false,
                'encryption' => false,
                'last_activity' => time(),
                'username' => '',
                'uuid' => ''
            ];

            $this->log("🔌 Новое подключение #$clientId");
        }
    }

    private function processConnections(): void {
        foreach ($this->connections as $clientId => &$connection) {
            // Проверка таймаута
            if (time() - $connection['last_activity'] > 30) {
                $this->log("⏰ Таймаут #$clientId");
                $this->disconnect($clientId);
                continue;
            }

            // Чтение данных
            $data = @fread($connection['socket'], 8192);
            if ($data === false || $data === '') {
                if (feof($connection['socket'])) {
                    $this->log("📤 Отключение #$clientId (EOF)");
                    $this->disconnect($clientId);
                }
                continue;
            }

            $connection['buffer'] .= $data;
            $connection['last_activity'] = time();

            // Обработка пакетов
            $this->processPackets($clientId, $connection);
        }
    }

    private function processPackets(int $clientId, array &$connection): void {
        while (strlen($connection['buffer']) > 0) {
            // Чтение длины пакета
            $offset = 0;
            $packetLength = $this->readVarInt($connection['buffer'], $offset);

            if ($packetLength === null || strlen($connection['buffer']) < $offset + $packetLength) {
                break;
            }

            $packetData = substr($connection['buffer'], $offset, $packetLength);
            $connection['buffer'] = substr($connection['buffer'], $offset + $packetLength);

            $this->handlePacket($clientId, $connection, $packetData);
        }
    }

    private function handlePacket(int $clientId, array &$connection, string $packetData): void {
        $offset = 0;
        $packetId = $this->readVarInt($packetData, $offset);

        if ($packetId === null) return;

        $this->log("📥 #$clientId Пакет 0x" . dechex($packetId) . " состояние: {$connection['state']}");

        switch ($connection['state']) {
            case 'handshake':
                $this->handleHandshake($clientId, $connection, $packetId, $packetData, $offset);
                break;

            case 'status':
                $this->handleStatus($clientId, $connection, $packetId, $packetData, $offset);
                break;

            case 'login':
                $this->handleLogin($clientId, $connection, $packetId, $packetData, $offset);
                break;

            case 'play':
                $this->handlePlay($clientId, $connection, $packetId, $packetData, $offset);
                break;
        }
    }

    private function handleHandshake(int $clientId, array &$connection, int $packetId, string $data, int $offset): void {
        if ($packetId !== 0x00) return;

        $protocol = $this->readVarInt($data, $offset);
        $address = $this->readString($data, $offset);
        $port = unpack('n', substr($data, $offset, 2))[1];
        $offset += 2;
        $nextState = $this->readVarInt($data, $offset);

        $this->log("🤝 #$clientId Handshake: протокол=$protocol, адрес=$address:$port, состояние=$nextState");

        if ($nextState === 1) {
            $connection['state'] = 'status';
        } elseif ($nextState === 2) {
            $connection['state'] = 'login';
        }
    }

    private function handleStatus(int $clientId, array &$connection, int $packetId, string $data, int $offset): void {
        if ($packetId === 0x00) {
            // Status Request
            $status = [
                "version" => [
                    "name" => "1.20.1",
                    "protocol" => 763
                ],
                "players" => [
                    "max" => $this->config['max_players'],
                    "online" => count(array_filter($this->connections, fn($c) => $c['state'] === 'play')),
                    "sample" => []
                ],
                "description" => [
                    "text" => $this->config['motd']
                ],
                "favicon" => "",
                "enforcesSecureChat" => false,
                "previewsChat" => false
            ];

            $this->sendPacket($clientId, 0x00, $this->writeString(json_encode($status)));
            $this->log("📊 #$clientId Отправлен статус");

        } elseif ($packetId === 0x01) {
            // Ping Request
            $payload = substr($data, $offset, 8);
            $this->sendPacket($clientId, 0x01, $payload);
            $this->log("🏓 #$clientId Pong отправлен");
        }
    }

    private function handleLogin(int $clientId, array &$connection, int $packetId, string $data, int $offset): void {
        if ($packetId !== 0x00) return;

        $username = $this->readString($data, $offset);
        $connection['username'] = $username;

        // Генерация UUID на основе имени
        $connection['uuid'] = $this->generateUUID($username);

        $this->log("🔑 #$clientId Login Start: $username");

        // Отправка Login Success
        $uuidBinary = hex2bin(str_replace('-', '', $connection['uuid']));
        $loginSuccess = $uuidBinary . $this->writeString($username);
        $this->sendPacket($clientId, 0x02, $loginSuccess);

        $this->log("✅ #$clientId Login Success отправлен");

        // Переход в состояние play
        $connection['state'] = 'play';

        // Отправка игровых пакетов
        $this->sendGamePackets($clientId, $connection);
    }

    private function handlePlay(int $clientId, array &$connection, int $packetId, string $data, int $offset): void {
        switch ($packetId) {
            case 0x00: // Teleport Confirm
                $this->log("📍 #$clientId Teleport Confirm");
                break;

            case 0x03: // Chat Message
                $message = $this->readString($data, $offset);
                $this->log("💬 #$clientId Чат: $message");
                break;

            case 0x04: // Client Settings
                $this->log("⚙️ #$clientId Client Settings получены");
                break;

            case 0x05: // Plugin Message (Serverbound)
                $channel = $this->readString($data, $offset);
                $this->log("🔌 #$clientId Plugin Message: $channel");
                break;

            case 0x0C: // Keep Alive (Serverbound)
                $keepAliveId = unpack('J', substr($data, $offset, 8))[1];
                $this->sendPacket($clientId, 0x24, pack('J', $keepAliveId));
                $this->log("💓 #$clientId Keep Alive: $keepAliveId");
                break;
        }
    }

    private function sendGamePackets(int $clientId, array $connection): void {
        $this->log("🎮 #$clientId Отправка игровых пакетов...");

        // 1. Feature Flags (0x5B) - обязателен для 1.20.1
        $this->sendPacket($clientId, 0x5B, $this->writeVarInt(0)); // Пустой список
        usleep(1000);

        // 2. Join Game (0x24)
        $joinGame = $this->createJoinGamePacket($clientId);
        $this->sendPacket($clientId, 0x24, $joinGame);
        usleep(1000);

        // 3. Server Difficulty (0x0D)
        $this->sendPacket($clientId, 0x0D, "\x01\x00"); // Easy, не заблокировано
        usleep(1000);

        // 4. Player Abilities (0x30)
        $this->sendPacket($clientId, 0x30, "\x06" . pack('f', 0.05) . pack('f', 0.1)); // Allow flying + creative, скорости
        usleep(1000);

        // 5. Player Info (0x32) - добавляем игрока в список
        $playerInfo = $this->createPlayerInfoPacket($connection);
        $this->sendPacket($clientId, 0x32, $playerInfo);
        usleep(1000);

        // 6. Declare Commands (0x11) - пустые команды
        $this->sendPacket($clientId, 0x11, $this->writeVarInt(0) . $this->writeVarInt(0));
        usleep(1000);

        // 7. Tags (0x5A) - пустые теги
        $tagsNBT = "\x0A\x00\x00"; // Пустой NBT Compound
        $this->sendPacket($clientId, 0x5A, $this->writeString($tagsNBT));
        usleep(1000);

        // 8. Declare Recipes (0x59) - пустые рецепты
        $this->sendPacket($clientId, 0x59, $this->writeVarInt(0));
        usleep(1000);

        // 9. Entity Metadata (0x43) - метаданные нашего игрока
        $this->sendPacket($clientId, 0x43, $this->writeVarInt(1) . "\xFF"); // Entity ID 1, пустые метаданные
        usleep(1000);

        // 10. Spawn Position (0x41)
        $spawn = $this->config['world']['spawn'];
        $x = (int)$spawn['x'];
        $y = (int)$spawn['y'];
        $z = (int)$spawn['z'];
        $position = (($x & 0x3FFFFFF) << 38) | (($z & 0x3FFFFFF) << 12) | ($y & 0xFFF);
        $this->sendPacket($clientId, 0x41, pack('J', $position) . pack('f', 0.0));
        usleep(1000);

        // 11. Time Update (0x4D)
        $this->sendPacket($clientId, 0x4D, pack('J', 0) . pack('J', 6000)); // Время мира
        usleep(1000);

        // 12. Player Position and Look (0x34)
        $positionPacket = $this->createPositionPacket($clientId);
        $this->sendPacket($clientId, 0x34, $positionPacket);
        usleep(1000);

        // 13. Keep Alive (0x24 - Clientbound Keep Alive)
        $keepAliveId = time();
        $this->sendPacket($clientId, 0x24, pack('J', $keepAliveId));

        $this->log("✨ #$clientId Игрок {$connection['username']} вошел в игру!");
    }

    private function createJoinGamePacket(int $clientId): string {
        $data = '';

        // Entity ID
        $data .= pack('N', 1);

        // Is hardcore
        $data .= "\x00";

        // Gamemode
        $data .= "\x01"; // Creative

        // Previous gamemode
        $data .= "\xFF"; // -1

        // World count
        $data .= $this->writeVarInt(1);

        // World names
        $data .= $this->writeString("minecraft:overworld");

        // Dimension codec (упрощенный NBT)
        $dimensionCodec = "\x0A\x00\x00"; // Пустой compound
        $data .= $this->writeString($dimensionCodec);

        // Current dimension
        $currentDimension = "\x0A\x00\x0Cminecraft:overworld\x00"; // Compound с именем мира
        $data .= $this->writeString($currentDimension);

        // World name
        $data .= $this->writeString("minecraft:overworld");

        // Hashed seed
        $data .= pack('J', $this->config['world']['seed']);

        // Max players
        $data .= $this->writeVarInt($this->config['max_players']);

        // View distance
        $data .= $this->writeVarInt(10);

        // Simulation distance
        $data .= $this->writeVarInt(10);

        // Reduced debug info
        $data .= "\x00";

        // Enable respawn screen
        $data .= "\x01";

        // Is debug
        $data .= "\x00";

        // Is flat
        $data .= "\x00";

        // Has death location
        $data .= "\x00";

        return $data;
    }

    private function createPlayerInfoPacket(array $connection): string {
        $data = '';

        // Action: Add Player (0x00)
        $data .= $this->writeVarInt(0x00);

        // Number of players
        $data .= $this->writeVarInt(1);

        // UUID
        $data .= hex2bin(str_replace('-', '', $connection['uuid']));

        // Username
        $data .= $this->writeString($connection['username']);

        // Number of properties
        $data .= $this->writeVarInt(0);

        // Gamemode
        $data .= $this->writeVarInt(1); // Creative

        // Ping
        $data .= $this->writeVarInt(0);

        // Has display name
        $data .= "\x00";

        // Listed
        $data .= "\x01";

        return $data;
    }

    private function createPositionPacket(int $clientId): string {
        $spawn = $this->config['world']['spawn'];

        $data = '';

        // X, Y, Z
        $data .= pack('d', $spawn['x']);
        $data .= pack('d', $spawn['y']);
        $data .= pack('d', $spawn['z']);

        // Yaw, Pitch
        $data .= pack('f', 0.0);
        $data .= pack('f', 0.0);

        // Flags
        $data .= "\x00";

        // Teleport ID
        $data .= $this->writeVarInt(1);

        return $data;
    }

    private function sendPacket(int $clientId, int $packetId, string $data): bool {
        if (!isset($this->connections[$clientId])) {
            return false;
        }

        $packet = $this->writeVarInt($packetId) . $data;
        $length = $this->writeVarInt(strlen($packet));
        $fullPacket = $length . $packet;

        $socket = $this->connections[$clientId]['socket'];
        $written = @fwrite($socket, $fullPacket);

        if ($written === false) {
            $this->disconnect($clientId);
            return false;
        }

        return true;
    }

    private function disconnect(int $clientId): void {
        if (isset($this->connections[$clientId])) {
            $username = $this->connections[$clientId]['username'];
            if ($username) {
                $this->log("👋 #$clientId Отключение игрока: $username");
            } else {
                $this->log("👋 #$clientId Отключение");
            }

            @fclose($this->connections[$clientId]['socket']);
            unset($this->connections[$clientId]);
        }
    }

    // Вспомогательные методы для работы с VarInt и строками

    private function readVarInt(string $buffer, int &$offset): ?int {
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
                return null; // VarInt слишком большой
            }
        } while ($byte & 0x80);

        return $value;
    }

    private function writeVarInt(int $value): string {
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

    private function readString(string $buffer, int &$offset): string {
        $length = $this->readVarInt($buffer, $offset);
        if ($length === null || $length === 0) {
            return '';
        }

        $string = substr($buffer, $offset, $length);
        $offset += $length;
        return $string;
    }

    private function writeString(string $string): string {
        return $this->writeVarInt(strlen($string)) . $string;
    }

    private function generateUUID(string $username): string {
        $hash = md5($username . time());
        return sprintf(
            '%08s-%04s-%04s-%04s-%12s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 12, 4),
            substr($hash, 16, 4),
            substr($hash, 20, 12)
        );
    }

    public function __destruct() {
        $this->running = false;

        foreach ($this->connections as $clientId => $connection) {
            @fclose($connection['socket']);
        }

        if ($this->socket) {
            @fclose($this->socket);
        }

        $this->log("🛑 Сервер остановлен");
    }
}

// Настройки сервера
$config = [
    'host' => '0.0.0.0',      // Адрес для прослушивания
    'port' => 25565,          // Порт сервера
    'max_players' => 20,      // Максимальное количество игроков
    'motd' => "§aPHP Minecraft Server §7| §f1.20.1\n§ePowered by PHP 8.2",
    'world' => [
        'name' => 'flat_world',
        'seed' => 1234567890,
        'spawn' => ['x' => 0.5, 'y' => 64.0, 'z' => 0.5]
    ]
];

// Создание и запуск сервера
try {
    $server = new MinecraftServer($config);
    $server->start();
} catch (Exception $e) {
    echo "Ошибка запуска сервера: " . $e->getMessage() . "\n";
}
