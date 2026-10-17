<?php
declare(strict_types=1);

/**
 * Тестовый клиент Protocol 763 (1.20.1): проверяет Status и полный Login→Play флоу.
 */

function vi(int $v): string {
    $v &= 0xFFFFFFFF; $o='';
    do { $t = $v & 0x7F; $v >>= 7; if ($v !== 0) $t |= 0x80; $o .= chr($t); } while ($v !== 0);
    return $o;
}
function frame(string $payload): string { return vi(strlen($payload)) . $payload; }

function readPacket($sock): ?array {
    // read varint length
    $len = 0; $shift = 0;
    while (true) {
        $b = fread($sock, 1);
        if ($b === false || strlen($b) === 0) return null;
        $byte = ord($b);
        $len |= ($byte & 0x7F) << $shift;
        if (($byte & 0x80) === 0) break;
        $shift += 7;
        if ($shift > 28) return null;
    }
    $payload = '';
    while (strlen($payload) < $len) {
        $chunk = fread($sock, $len - strlen($payload));
        if ($chunk === false || $chunk === '') return null;
        $payload .= $chunk;
    }
    $off = 0;
    $id = vi_read($payload, $off);
    return ['id' => $id, 'data' => substr($payload, $off)];
}
function vi_read(string $buf, int &$off): int {
    $v = 0; $s = 0;
    while ($off < strlen($buf)) {
        $b = ord($buf[$off++]);
        $v |= ($b & 0x7F) << $s;
        if (($b & 0x80) === 0) break;
        $s += 7;
    }
    return $v;
}

$host = $argv[1] ?? '127.0.0.1';
$port = (int)($argv[2] ?? 25599);
$mode = $argv[3] ?? 'login'; // status | login

$sock = stream_socket_client("tcp://$host:$port", $errno, $errstr, 5);
if (!$sock) { fwrite(STDERR, "connect failed: $errstr\n"); exit(2); }
stream_set_timeout($sock, 10);

// Handshake
$hs = vi(0) . vi(763) . vi(strlen('test')) . 'test' . pack('n', $port) . vi($mode === 'status' ? 1 : 2);
fwrite($sock, frame($hs));

if ($mode === 'status') {
    fwrite($sock, frame(vi(0x00)));
    $p = readPacket($sock);
    if ($p === null) { echo "STATUS: no response\n"; exit(1); }
    echo "STATUS packet id=0x" . dechex($p['id']) . " json=" . substr($p['data'], 0, 300) . "\n";
    exit(0);
}

// Login Start
$name = 'TesterBot';
$ls = vi(0x00) . vi(strlen($name)) . $name;
fwrite($sock, frame($ls));

$p = readPacket($sock);
if ($p === null) { echo "LOGIN: no response after LoginStart\n"; exit(1); }
echo "<- id=0x" . dechex($p['id']) . " len=" . strlen($p['data']) . "\n";
if ($p['id'] !== 0x02) { echo "expected LoginSuccess(0x02), got disconnect? data=" . substr($p['data'],0,200) . "\n"; exit(1); }
echo "LoginSuccess OK, uuid-ish data=" . bin2hex(substr($p['data'],0,20)) . "\n";

// Login Acknowledged
fwrite($sock, frame(vi(0x03)));

$seen = [];
$deadline = microtime(true) + 15;
while (microtime(true) < $deadline) {
    $p = readPacket($sock);
    if ($p === null) break;
    $seen[] = $p['id'];
    $desc = '';
    if ($p['id'] === 0x27) { // CB_PLAY_LOGIN (Join Game), Protocol 763
        $off = 0;
        $eid = unpack('V', strrev(substr($p['data'], $off, 4)))[1]; $off += 4;
        $gamemode = ord($p['data'][$off]); $off += 1;
        $dim = vi_read($p['data'], $off);
        $desc = " entityId=$eid gamemode=$gamemode dimId=$dim";
    }
    if ($p['id'] === 0x3A) { // CB_PLAY_PLAYER_POSITION (Teleport), Protocol 763
        $f = array_values(unpack('E*', substr($p['data'], 0, 24)));
        $desc = " x={$f[0]} y={$f[1]} z={$f[2]}";
    }
    echo "<- PLAY id=0x" . dechex($p['id']) . " len=" . strlen($p['data']) . $desc . "\n";
    if ($p['id'] === 0x22) { // CB_PLAY_KEEP_ALIVE S2C: respond SB 0x15
        $ka = substr($p['data'], 0, 8);
        fwrite($sock, frame(vi(0x15) . $ka));
        echo "-> KeepAlive reply\n";
    }
    if ($p['id'] === 0x3A) { // teleport -> SB_PLAY_ACCEPT_TELEPORTATION (0x00)
        $tmpoff = 0;
        $tid = vi_read($p['data'], $tmpoff);
        fwrite($sock, frame(vi(0x00) . vi($tid)));
        echo "-> AcceptTeleportation $tid\n";
    }
    if (count($seen) >= 25) break;
}

$need = [0x27, 0x38, 0x3A, 0x21, 0x22]; // JoinGame, PlayerInfoUpdate, Position, GameEvent, KeepAlive
$missing = array_diff($need, $seen);
echo "TOTAL packets seen: " . count($seen) . "\n";
if ($missing) {
    echo "RESULT: FAIL missing " . implode(',', array_map(fn($x)=>'0x'.dechex($x), $missing)) . "\n";
    exit(1);
}
echo "RESULT: PASS full 1.20.1 login->play flow verified\n";
exit(0);
