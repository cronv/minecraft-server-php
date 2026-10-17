<?php
declare(strict_types=1);

/**
 * Regression-тесты входа Protocol 763 (Minecraft 1.20.1).
 *
 * Загрузка server.php происходит с отключённым автозапуском: определяем
 * константу MC_SERVER_NO_AUTOSTART — блок «Запуск» в конце файла тогда не
 * создаёт сокет и не входит в событийный цикл. Тестируются реальные классы
 * сервера: VarInt, StringHandler, PacketCodec, PacketFactory, Uuid,
 * HandshakeHandler, LoginHandler, processBuffer/handlePacket (машина состояний).
 *
 * Проверки байтовые и по состоянию соединения — не по лог-сообщениям.
 */

if (!defined('MC_SERVER_NO_AUTOSTART')) {
    define('MC_SERVER_NO_AUTOSTART', true);
}

require __DIR__ . '/../server.php';

// ---------------------------------------------------------------------------
// Мини-фреймворк
// ---------------------------------------------------------------------------

$GLOBALS['__pass'] = 0;
$GLOBALS['__fail'] = 0;

function check(bool $cond, string $name): void
{
    if ($cond) {
        $GLOBALS['__pass']++;
        echo "  ✅ $name\n";
    } else {
        $GLOBALS['__fail']++;
        echo "  ❌ FAIL: $name\n";
    }
}

function thrownCheck(callable $fn, string $name): void
{
    try {
        $fn();
        check(false, $name . ' (исключение НЕ выброшено)');
    } catch (Throwable) {
        check(true, $name);
    }
}

function hex(string $s): string
{
    return bin2hex($s);
}

// ---------------------------------------------------------------------------
// Эталонные построители пакетов (независимая реализация для сверки)
// ---------------------------------------------------------------------------

function refVi(int $v): string
{
    $v &= 0xFFFFFFFF;
    $o = '';
    do {
        $t = $v & 0x7F;
        $v >>= 7;
        if ($v !== 0) {
            $t |= 0x80;
        }
        $o .= chr($t);
    } while ($v !== 0);
    return $o;
}

function refFrame(string $payload): string
{
    return refVi(strlen($payload)) . $payload;
}

function refViRead(string $buf, int &$off): ?int
{
    $v = 0;
    $s = 0;
    while ($off < strlen($buf)) {
        $b = ord($buf[$off++]);
        $v |= ($b & 0x7F) << $s;
        if (($b & 0x80) === 0) {
            return $v;
        }
        $s += 7;
        if ($s > 28) {
            return null;
        }
    }
    return null;
}

function handshakePacket(int $protocol, int $nextState, string $host = 'myapp.local', int $port = 25565): string
{
    return refFrame(refVi(0x00) . refVi($protocol) . refVi(strlen($host)) . $host . pack('n', $port) . refVi($nextState));
}

function loginStartPacket(string $username, ?string $sigData = null, string $extra = ''): string
{
    $p = refVi(0x00) . refVi(strlen($username)) . $username;
    $p .= $sigData === null ? chr(0) : chr(1) . $sigData;
    $p .= $extra;
    return refFrame($p);
}

function loginAcknowledgedPacket(): string
{
    return refFrame(refVi(0x03));
}

// ---------------------------------------------------------------------------
// Инфраструктура: loopback-пара сокетов + MinecraftServer без start()
// ---------------------------------------------------------------------------

/**
 * @return array{0: MinecraftServer, 1: ConnectionManager, 2: resource, 3: resource}
 */
function makeHarness(): array
{
    $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($listener === false) {
        throw new RuntimeException("Не удалось создать тестовый listener: $errstr");
    }

    $name = stream_socket_get_name($listener, false);
    $client = @stream_socket_client("tcp://$name", $cErrno, $cErrstr, 2);
    if ($client === false) {
        throw new RuntimeException("Тестовый connect failed: $cErrstr");
    }
    $serverSide = @stream_socket_accept($listener, 2);
    if ($serverSide === false) {
        throw new RuntimeException('Тестовый accept failed');
    }
    fclose($listener);
    stream_set_blocking($serverSide, false);
    stream_set_blocking($client, false);

    $server = new MinecraftServer(new ServerConfig(port: 25599));

    $cmRef = new ReflectionProperty(MinecraftServer::class, 'connectionManager');
    $cmRef->setAccessible(true);
    /** @var ConnectionManager $cm */
    $cm = $cmRef->getValue($server);
    $cm->acceptNewConnection($serverSide);

    return [$server, $cm, $serverSide, $client];
}

function connOf(ConnectionManager $cm): ClientConnection
{
    $connRef = new ReflectionProperty(ConnectionManager::class, 'connections');
    $connRef->setAccessible(true);
    $conns = $connRef->getValue($cm);
    $conn = reset($conns);
    if (!$conn instanceof ClientConnection) {
        throw new RuntimeException('Нет соединения в менеджере');
    }
    return $conn;
}

/** Читает все доступные байты из клиентской стороны сокета (с микро-ожиданием). */
function drainSocket($client, float $timeoutSec = 0.5): string
{
    $out = '';
    $deadline = microtime(true) + $timeoutSec;
    while (microtime(true) < $deadline) {
        $chunk = @fread($client, 65535);
        if ($chunk !== false && $chunk !== '') {
            $out .= $chunk;
            continue;
        }
        if ($out !== '') {
            break;
        }
        usleep(2000);
    }
    return $out;
}

/** Разбирает поток кадров в список [packetId, payload]. */
function parseFrames(string $stream): array
{
    $packets = [];
    $off = 0;
    $len = strlen($stream);
    while ($off < $len) {
        $l = refViRead($stream, $off);
        if ($l === null || $off + $l > $len) {
            break;
        }
        $payload = substr($stream, $off, $l);
        $off += $l;
        $pOff = 0;
        $id = refViRead($payload, $pOff);
        $packets[] = [(int) $id, substr($payload, $pOff)];
    }
    return $packets;
}

// ---------------------------------------------------------------------------
// 1. VarInt: кодирование, декодирование, границы
// ---------------------------------------------------------------------------

echo "== 1. VarInt (Protocol 763) ==\n";

check(hex(VarInt::write(0)) === '00', 'VarInt.write(0) = 00');
check(hex(VarInt::write(1)) === '01', 'VarInt.write(1) = 01');
check(hex(VarInt::write(127)) === '7f', 'VarInt.write(127) = 7f');
check(hex(VarInt::write(128)) === '8001', 'VarInt.write(128) = 8001');
check(hex(VarInt::write(255)) === 'ff01', 'VarInt.write(255) = ff01');
check(hex(VarInt::write(763)) === 'fb05', 'VarInt.write(763) = fb05 (протокол 1.20.1)');
check(hex(VarInt::write(2097151)) === 'ffff7f', 'VarInt.write(2097151) = ffff7f');

foreach ([0, 1, 63, 127, 128, 255, 256, 763, 300, 65534, 2097151] as $n) {
    $off = 0;
    check(VarInt::read(VarInt::write($n), $off) === $n, "VarInt round-trip $n");
}

$off = 0;
check(VarInt::read("\xff", $off) === null, 'Неполный VarInt → null (ждём данных)');

thrownCheck(static function (): void {
    $off = 0;
    VarInt::read("\x80\x80\x80\x80\x80\x80\x00", $off);
}, 'VarInt из 6+ байт → RuntimeException (прежний лимит shift>35 пропускал)');

// ---------------------------------------------------------------------------
// 2. Frame decoder: частичные чтения, склейка нескольких кадров
// ---------------------------------------------------------------------------

echo "\n== 2. Кадрирование / инкрементальный разбор ==\n";

$hs = handshakePacket(763, 2);
$ls = loginStartPacket('turel1983');

// Два кадра в одном чтении + хвост следующего кадра
[$server, $cm, , ] = makeHarness();
$conn = connOf($cm);
$conn->buffer = $hs . $ls . "\x01";
$server->processBuffer($conn->id, $conn);
check($conn->state === ProtocolState::Login, 'Handshake+LoginStart в одном чтении → состояние Login');
check($conn->buffer === "\x01", 'Хвост неполного кадра остаётся в буфере (ждём продолжение)');

// Кадр, пришедший по частям
[$server2, $cm2, , ] = makeHarness();
$conn2 = connOf($cm2);
$half = intdiv(strlen($hs), 2);
$conn2->buffer = substr($hs, 0, $half);
$server2->processBuffer($conn2->id, $conn2);
check($conn2->state === ProtocolState::Handshake, 'Половина кадра Handshake — состояние не меняем');
check($conn2->buffer === substr($hs, 0, $half), 'Неполный кадр остаётся в буфере нетронутым');
$conn2->buffer .= substr($hs, $half);
$server2->processBuffer($conn2->id, $conn2);
check($conn2->state === ProtocolState::Login, 'Вторая половина достроила кадр → Login');
check($conn2->buffer === '', 'Буфер очищен после полного кадра');
check($conn2->protocol === 763, 'protocol=763 сохранён из Handshake');

// wrapPacket/unwrapPacket
$frame = PacketCodec::wrapPacket(0x02, 'abc');
check($frame === "\x04\x02abc", 'wrapPacket: длина включает packet ID (0x04 = id+3)');
$un = PacketCodec::unwrapPacket($frame);
check($un === [0x02, 'abc'], 'unwrapPacket round-trip');

// ---------------------------------------------------------------------------
// 3. Handshake: nextState и версия протокола
// ---------------------------------------------------------------------------

echo "\n== 3. Handshake: машина состояний / версии ==\n";

[$s3, $cm3, , ] = makeHarness();
$c3 = connOf($cm3);
$c3->buffer = handshakePacket(763, 1);
$s3->processBuffer($c3->id, $c3);
check($c3->state === ProtocolState::Status, 'nextState=1 → Status');

[$s4, $cm4, , ] = makeHarness();
$c4 = connOf($cm4);
$c4->buffer = handshakePacket(763, 3);
$s4->processBuffer($c4->id, $c4);
check($c4->state === ProtocolState::Handshake, 'Недопустимый nextState=3 → ни в какое состояние не входим');
check($cm4->getConnection($c4->id) === null, 'Недопустимый nextState → соединение закрыто');

// Неизвестная версия протокола (764) в Login → явный Login Disconnect (CB 0x00)
[$s5, $cm5, , $cli5] = makeHarness();
$c5 = connOf($cm5);
$c5->buffer = handshakePacket(764, 2);
$s5->processBuffer($c5->id, $c5);
$parsed = parseFrames(drainSocket($cli5));
check(count($parsed) === 1 && $parsed[0][0] === 0x00, 'Protocol 764 Login → ровно один Login Disconnect (0x00)');
check($c5->state === ProtocolState::Login, 'Отклонение отправлено в состоянии Login (ID 0x00 = Disconnect)');
check($cm5->getConnection($c5->id) === null, 'Неподдерживаемая версия → соединение закрыто');
if (count($parsed) === 1) {
    $reasonJson = substr($parsed[0][1], 1); // пропускаем VarInt-длину строки
    check(str_contains($reasonJson, '764') && str_contains($reasonJson, '763'), 'Причина Disconnect содержит номера версий');
}

// ---------------------------------------------------------------------------
// 4. Login Start: необязательные поля + точный Login Success
// ---------------------------------------------------------------------------

echo "\n== 4. Login Start / Login Success (эталон байтов) ==\n";

$offlineUuid = Uuid::fromUsername('turel1983');
check($offlineUuid === 'dde8535b-0ddd-3a45-9c3e-0706e0f4410b', 'Offline UUID совпадает с ожидаемым');

$expectedSuccessPayload = hex2bin('dde8535b0ddd3a459c3e0706e0f4410b') . chr(9) . 'turel1983' . chr(0);
$expectedSuccessFrame = "\x1c\x02" . $expectedSuccessPayload; // len=28 (id+data), id=0x02
check(strlen($expectedSuccessFrame) === 29, 'Эталонный кадр Login Success = 29 байт (1 length + 28 payload)');

// a) Login Start БЕЗ подписи (hasSig=false) — минимальный vanilla offline
[$s6, $cm6, , $cli6] = makeHarness();
$c6 = connOf($cm6);
$c6->buffer = handshakePacket(763, 2) . loginStartPacket('turel1983');
$s6->processBuffer($c6->id, $c6);
$p6 = parseFrames(drainSocket($cli6));
check(count($p6) === 1, 'Login Start без подписи → РОВНО ОДИН ответный пакет (никакого Plugin Request!)');
check(isset($p6[0]) && $p6[0][0] === 0x02, 'Ответ — Login Success (CB 0x02)');
check(isset($p6[0]) && hex($p6[0][1]) === hex($expectedSuccessPayload), 'Login Success payload побайтово равен эталону 763');
check($c6->uuid === 'dde8535b-0ddd-3a45-9c3e-0706e0f4410b', 'Сервер использует offline UUID, а не случайный');
check($c6->state === ProtocolState::Login, 'После Login Success состояние остаётся Login (ждём ACK)');

// b) Login Start С подписью (hasSig=true, корректные длины)
$sig = pack('J', 1234567890) . chr(3) . 'KEY' . chr(2) . 'SG';
[$s7, $cm7, , $cli7] = makeHarness();
$c7 = connOf($cm7);
$c7->buffer = handshakePacket(763, 2) . loginStartPacket('turel1983', $sig);
$s7->processBuffer($c7->id, $c7);
$p7 = parseFrames(drainSocket($cli7));
check(count($p7) === 1 && $p7[0][0] === 0x02, 'Login Start с подписью → ровно один Login Success');

// c) Битый Login Start: pubKeyLen больше остатка → Disconnect с причиной, без зависания
[$s8, $cm8, , $cli8] = makeHarness();
$c8 = connOf($cm8);
$c8->buffer = handshakePacket(763, 2) . loginStartPacket('turel1983', pack('J', 0) . chr(0x7F) . 'short');
$s8->processBuffer($c8->id, $c8);
$p8 = parseFrames(drainSocket($cli8));
check(isset($p8[0]) && $p8[0][0] === 0x00, 'Битые опциональные поля → Login Disconnect (0x00), а не тишина');
check($cm8->getConnection($c8->id) === null, 'Битый Login Start → соединение закрыто');

// d) Усечённая username-строка (обещана длина 5, фактически 2) → отклонение
[$s9, $cm9, , ] = makeHarness();
$c9 = connOf($cm9);
$c9->buffer = handshakePacket(763, 2) . refFrame(refVi(0x00) . chr(5) . 'ab');
$s9->processBuffer($c9->id, $c9);
check($cm9->getConnection($c9->id) === null, 'Усечённая username-строка → соединение закрыто');

// ---------------------------------------------------------------------------
// 5. Login Acknowledged → переход в Play
// ---------------------------------------------------------------------------

echo "\n== 5. Login Acknowledged → Play ==\n";

[$s10, $cm10, , $cli10] = makeHarness();
$c10 = connOf($cm10);
$c10->buffer = handshakePacket(763, 2) . loginStartPacket('turel1983');
$s10->processBuffer($c10->id, $c10);
drainSocket($cli10); // забираем Login Success
check($c10->state === ProtocolState::Login, 'До ACK — Login');

$c10->buffer = loginAcknowledgedPacket();
$s10->processBuffer($c10->id, $c10);
check($c10->state === ProtocolState::Play, 'После SB 0x03 (Login Acknowledged) → Play (1.20.1 без Configuration)');

$play = parseFrames(drainSocket($cli10, 1.0));
$ids = array_map(static fn(array $p): int => $p[0], $play);
check(in_array(0x27, $ids, true), 'Join Game (CB 0x27) отправлен после ACK');
check(in_array(0x38, $ids, true), 'Player Info Update (CB 0x38) отправлен');
check(in_array(0x3A, $ids, true), 'Teleport (CB 0x3A) отправлен');
check(in_array(0x21, $ids, true), 'Game Event (CB 0x21) отправлен');
// В Protocol 763 ID 0x1F — Update Mob Effect; он НЕ должен встречаться в стартовой пачке
check(!in_array(0x1F, $ids, true), 'В стартовой play-пачке нет пакета 0x1F (Mob Effect)');
check(!in_array(0x04, $ids, true), 'В Play-последовательности НЕТ login Plugin Request (0x04)');
check($ids !== [] && $ids[0] === 0x27, 'Первый play-пакет — Join Game');

// Accept Teleportation (SB 0x00) обрабатывается корректно
$teleportData = null;
foreach ($play as [$id, $data]) {
    if ($id === 0x3A) {
        $teleportData = $data;
        break;
    }
}
check($teleportData !== null, 'Найден пакет телепорта');
if ($teleportData !== null) {
    $tOff = 0;
    $confirmId = (int) refViRead($teleportData, $tOff);
    $c10->buffer = refFrame(refVi(0x00) . refVi($confirmId));
    $s10->processBuffer($c10->id, $c10);
    check($c10->state === ProtocolState::Play, 'Accept Teleportation обработан, состояние Play сохранено');
}

// ---------------------------------------------------------------------------
// 6. Регрессия первопричины DecoderException: никогда не слать CB 0x04 в Login
// ---------------------------------------------------------------------------

echo "\n== 6. Регрессия DecoderException (Packet 0/4) ==\n";

[$s11, $cm11, , $cli11] = makeHarness();
$c11 = connOf($cm11);
$c11->buffer = handshakePacket(763, 2) . loginStartPacket('turel1983') . loginAcknowledgedPacket();
$s11->processBuffer($c11->id, $c11);
usleep(2_200_000); // прежний fallback срабатывал через 2 секунды
$full = parseFrames(drainSocket($cli11, 1.0));
$allIds = array_map(static fn(array $p): int => $p[0], $full);
check(!in_array(0x04, $allIds, true), 'Ни одного Login Plugin Request (CB 0x04) за весь flow — причина DecoderException устранена');
check($allIds !== [] && $allIds[0] === 0x02, 'Первый ответ сервера — Login Success');
check(in_array(0x27, $allIds, true), 'Join Game присутствует (ACK обработан)');

// Сценарий из логов: клиент прислал только Login Start и НЕ шлёт ACK —
// сервер обязан просто держать соединение живым (без таймаутных пакетов).
[$s12, $cm12, , $cli12] = makeHarness();
$c12 = connOf($cm12);
$c12->buffer = handshakePacket(763, 2) . loginStartPacket('turel1983');
$s12->processBuffer($c12->id, $c12);
usleep(2_200_000);
$late = parseFrames(drainSocket($cli12, 0.5));
$lateIds = array_map(static fn(array $p): int => $p[0], $late);
check($lateIds === [0x02], 'Клиент без ACK → сервер молча ждёт (только Login Success, никаких пакетов через 2 сек)');
check($cm12->getConnection($c12->id) !== null, 'Соединение без ACK остаётся открытым (EOF не эмулируем)');

// ---------------------------------------------------------------------------
// Итог
// ---------------------------------------------------------------------------

echo "\n=================================\n";
printf("PASS: %d, FAIL: %d\n", $GLOBALS['__pass'], $GLOBALS['__fail']);
exit($GLOBALS['__fail'] === 0 ? 0 : 1);
