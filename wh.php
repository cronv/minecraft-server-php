<?php
/**
 * Minecraft TCP Proxy на PHP 8.2 — с расшифровкой пакетов 1.20.1
 * Логирует HEX-данные в hex.log и ЧИТАЕМЫЕ JSON-события в json.log
 * Клиенты подключаются к 127.0.0.1:25565
 * Прокси перенаправляет на 127.0.0.1:25566 (реальный сервер)
 */

$PROXY_HOST = '127.0.0.1';
$PROXY_PORT = 25565;
$REAL_SERVER_HOST = '127.0.0.1';
$REAL_SERVER_PORT = 25566;
$speed = 10000; // 10ms = 10000

$LOG_HEX = 'hex.log';
$LOG_JSON = 'json.log';

// Очистка логов при запуске
file_put_contents($LOG_HEX, '');
file_put_contents($LOG_JSON, '');

echo "🚀 Minecraft Proxy запущен на {$PROXY_HOST}:{$PROXY_PORT}\n";
echo "➡️ Перенаправление на {$REAL_SERVER_HOST}:{$REAL_SERVER_PORT}\n";
echo "💡 Подключайтесь к {$PROXY_HOST}:{$PROXY_PORT} в Minecraft\n";
echo "Нажмите Ctrl+C для остановки\n\n";

// Создаём серверный сокет
$server = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
if (!$server) {
    die("❌ Не удалось создать сокет сервера\n");
}

socket_set_option($server, SOL_SOCKET, SO_REUSEADDR, 1);
socket_bind($server, $PROXY_HOST, $PROXY_PORT);
socket_listen($server, 5);

$clients = []; // [id => ['client' => socket, 'server' => socket, 'state' => 'handshake/login/play', 'last_activity' => int]]

while (true) {
    $read = [$server];
    foreach ($clients as $conn) {
        $read[] = $conn['client'];
        if ($conn['server']) {
            $read[] = $conn['server'];
        }
    }

    $write = null;
    $except = null;
    $ready = socket_select($read, $write, $except, 1);

    if ($ready === false) {
        break;
    }

    // Новое подключение
    if (in_array($server, $read)) {
        $clientSocket = @socket_accept($server);
        if ($clientSocket) {
            $serverSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            if (!$serverSocket) {
                socket_close($clientSocket);
                continue;
            }

            if (!socket_connect($serverSocket, $REAL_SERVER_HOST, $REAL_SERVER_PORT)) {
                socket_close($serverSocket);
                socket_close($clientSocket);
                continue;
            }

            $clientId = uniqid();
            $clients[$clientId] = [
                'client' => $clientSocket,
                'server' => $serverSocket,
                'state' => 'handshake', // Начинаем с handshake
                'last_activity' => time()
            ];

            echo "🟢 Новое подключение клиента: {$clientId}\n";
        }
    }

    // Обработка данных
    foreach ($clients as $id => &$conn) {
        $client = $conn['client'];
        $server = $conn['server'];
        $state = $conn['state'];

        // Данные от клиента
        if ($client && in_array($client, $read)) {
            $data = socket_read($client, 8192, PHP_BINARY_READ);
            if ($data === false || strlen($data) === 0) {
                echo "🔴 Клиент {$id} отключился\n";
                socket_close($client);
                socket_close($server);
                unset($clients[$id]);
                continue;
            }

            // Логируем как HEX и расшифровываем
            logPacket($data, 'client_to_server', $id, $state);

            // Пересылаем на сервер
            socket_write($server, $data, strlen($data));

            // Обновляем состояние, если это handshake
            if ($state === 'handshake' && strlen($data) >= 5) {
                $packetId = unpack('C', substr($data, 0, 1))[1];
                if ($packetId === 0x00) { // Handshake
                    $pos = 1;
                    $varintLength = decodeVarint(substr($data, $pos), $read);
                    $pos += $read;
                    $protocolVersion = decodeVarint(substr($data, $pos), $read);
                    $pos += $read;
                    $serverAddressLength = decodeVarint(substr($data, $pos), $read);
                    $pos += $read;
                    $serverAddress = substr($data, $pos, $serverAddressLength);
                    $pos += $serverAddressLength;
                    $serverPort = unpack('n', substr($data, $pos, 2))[1];
                    $pos += 2;
                    $nextState = unpack('C', substr($data, $pos, 1))[1];

                    $conn['state'] = match ($nextState) {
                        1 => 'login',
                        2 => 'play',
                        default => 'unknown'
                    };
                }

                if ($packetId === 0x1F) {
                    var_dump('thissss');
                }

                // connect: 18, 15, 3,
                if (in_array($packetId, [27, 13, 3])) {
                    var_dump(bin2hex($data));
//                    var_dump([
//                        'id' => $packetId,
//                        'name' => 'chat_message',
//                        'message_json' => $messageJson,
//                        'message_parsed' => $messageData,
//                        'raw_bytes' => bin2hex($data), // для отладки
//                    ]);
                }
                var_dump($packetId);
            }

            $conn['last_activity'] = time();
        }

        // Данные от сервера
        if ($server && in_array($server, $read)) {
            $data = socket_read($server, 8192, PHP_BINARY_READ);
            if ($data === false || strlen($data) === 0) {
                echo "🔴 Сервер отключился для клиента {$id}\n";
                socket_close($client);
                socket_close($server);
                unset($clients[$id]);
                continue;
            }

            logPacket($data, 'server_to_client', $id, $state);

            socket_write($client, $data, strlen($data));
            $conn['last_activity'] = time();
        }
    }

    // Удаление неактивных
    foreach ($clients as $id => $conn) {
        if (time() - $conn['last_activity'] > 60) {
            echo "⏳ Закрываем неактивное соединение {$id}\n";
            socket_close($conn['client']);
            socket_close($conn['server']);
            unset($clients[$id]);
        }
    }

    usleep($speed); // 10ms
}

socket_close($server);

// ======== ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ========

/**
 * Декодирует VarInt из байтов (Minecraft протокол)
 * @param string $data Байты
 * @param int $read Сколько байт было прочитано (возвращается по ссылке)
 * @return int Значение VarInt
 */
function decodeVarint(string $data, &$read) {
    $value = 0;
    $shift = 0;
    $read = 0;

    while ($read < strlen($data)) {
        $byte = ord($data[$read]);
        $read++;

        $value |= ($byte & 0x7F) << $shift;
        if (($byte & 0x80) === 0) {
            break;
        }
        $shift += 7;
        if ($shift >= 32) {
            break; // Защита от переполнения
        }
    }

    return $value;
}

/**
 * Декодирует строку в формате Minecraft (VarInt + UTF-8)
 * @param string $data
 * @param int $read
 * @return string
 */
function decodeString(string $data, &$read) {
    $length = decodeVarint($data, $read);
    $string = substr($data, $read, $length);
    $read += $length;
    return $string;
}

/**
 * Логирует пакет в HEX и JSON (с расшифровкой)
 */
function logPacket(string $data, string $direction, string $clientId, string $state) {
    global $LOG_HEX, $LOG_JSON;

    $hex = bin2hex($data);
    $timestamp = date('Y-m-d H:i:s');

    // HEX-лог (остаётся для отладки)
    file_put_contents($LOG_HEX, "[{$timestamp}] {$direction} ({$clientId}) | {$hex}\n", FILE_APPEND);

    // JSON-лог — с расшифровкой
    $json = [
        'timestamp' => $timestamp,
        'client_id' => $clientId,
        'direction' => $direction,
        'state' => $state,
        'length' => strlen($data),
        'hex' => $hex,
        'bytes' => array_map('ord', str_split($data))
    ];

    // Расшифровка пакетов по состоянию
    if (strlen($data) > 0) {
        $packetId = ord($data[0]);

        switch ($state) {
            case 'handshake':
                if ($packetId === 0x00) { // Handshake
                    $pos = 1;
                    $varintLength = decodeVarint(substr($data, $pos), $read);
                    $pos += $read;
                    $protocolVersion = decodeVarint(substr($data, $pos), $read);
                    $pos += $read;
                    $serverAddressLength = decodeVarint(substr($data, $pos), $read);
                    $pos += $read;
                    $serverAddress = substr($data, $pos, $serverAddressLength);
                    $pos += $serverAddressLength;
                    $serverPort = unpack('n', substr($data, $pos, 2))[1];
                    $pos += 2;
                    $nextState = ord($data[$pos]);

                    $json['packet'] = [
                        'id' => $packetId,
                        'name' => 'handshake',
                        'protocol_version' => $protocolVersion,
                        'server_address' => $serverAddress,
                        'server_port' => $serverPort,
                        'next_state' => match ($nextState) {
                            1 => 'login',
                            2 => 'play',
                            default => 'unknown'
                        }
                    ];
                }
                break;

            case 'login':
                if ($packetId === 0x00) { // Login Start
                    $pos = 1;
                    $username = decodeString(substr($data, $pos), $read);
                    $json['packet'] = [
                        'id' => $packetId,
                        'name' => 'login_start',
                        'username' => $username
                    ];
                } elseif ($packetId === 0x01) { // Login Acknowledge (от сервера)
                    $json['packet'] = [
                        'id' => $packetId,
                        'name' => 'login_acknowledge',
                        'message' => 'Server accepted login'
                    ];
                }
                break;

            case 'play':
                // Chat Message (0x01)
                if ($packetId === 0x01) {
                    $pos = 1;
                    $message = decodeString(substr($data, $pos), $read);
                    $json['packet'] = [
                        'id' => $packetId,
                        'name' => 'chat_message',
                        'message' => $message
                    ];
                }
                // Keep Alive (0x21)
                elseif ($packetId === 0x21) {
                    $pos = 1;
                    $keepAliveId = decodeVarint(substr($data, $pos), $read);
                    $json['packet'] = [
                        'id' => $packetId,
                        'name' => 'keep_alive',
                        'keep_alive_id' => $keepAliveId
                    ];
                }
                // Player Position (0x12)
                elseif ($packetId === 0x12) {
                    $pos = 1;
                    $x = unpack('d', substr($data, $pos, 8))[1]; $pos += 8;
                    $y = unpack('d', substr($data, $pos, 8))[1]; $pos += 8;
                    $z = unpack('d', substr($data, $pos, 8))[1]; $pos += 8;
                    $yaw = unpack('f', substr($data, $pos, 4))[1]; $pos += 4;
                    $pitch = unpack('f', substr($data, $pos, 4))[1]; $pos += 4;
                    $onGround = ord($data[$pos]);

                    $json['packet'] = [
                        'id' => $packetId,
                        'name' => 'player_position',
                        'position' => ['x' => $x, 'y' => $y, 'z' => $z],
                        'look' => ['yaw' => $yaw, 'pitch' => $pitch],
                        'on_ground' => (bool)$onGround
                    ];
                }
                // Spawn Player (0x05)
                elseif ($packetId === 0x05) {
                    $pos = 1;
                    $entityId = decodeVarint(substr($data, $pos), $read); $pos += $read;
                    $playerName = decodeString(substr($data, $pos), $read); $pos += $read;
                    $x = unpack('d', substr($data, $pos, 8))[1]; $pos += 8;
                    $y = unpack('d', substr($data, $pos, 8))[1]; $pos += 8;
                    $z = unpack('d', substr($data, $pos, 8))[1]; $pos += 8;
                    $yaw = unpack('f', substr($data, $pos, 4))[1]; $pos += 4;
                    $pitch = unpack('f', substr($data, $pos, 4))[1]; $pos += 4;

                    $json['packet'] = [
                        'id' => $packetId,
                        'name' => 'spawn_player',
                        'entity_id' => $entityId,
                        'username' => $playerName,
                        'position' => ['x' => $x, 'y' => $y, 'z' => $z],
                        'look' => ['yaw' => $yaw, 'pitch' => $pitch]
                    ];
                }
                break;
        }
    }

    file_put_contents($LOG_JSON, json_encode($json, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n", FILE_APPEND);
}
