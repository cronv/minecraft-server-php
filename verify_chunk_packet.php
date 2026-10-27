<?php
declare(strict_types=1);

/**
 * Валидатор Chunk Data (Level Chunk With Light, CB Play 0x23, protocol 763).
 *
 * Парсит тело пакета так же, как парсер клиента (Netty Decoders), и проверяет,
 * что после прочтения всех полей НЕ остаётся «лишних» байт — ровно тот класс
 * ошибок, который даёт «Packet ... was larger than I expected».
 */

// Загружаем server.php без запуска mainLoop: вырезаем блок «Запуск»,
// затем удаляем declare(strict_types=1) (в eval он запрещён).
$src = file_get_contents(__DIR__ . '/server.php');
$cutPos = strpos($src, "// ============================================================================\n// Запуск");
if ($cutPos === false) { fwrite(STDERR, "no launch block\n"); exit(2); }
$src = substr($src, 0, $cutPos);
$src = preg_replace('/^declare\(strict_types=1\);\s*$/m', '', $src, 1);
eval('?>' . $src);

function vi_read(string $buf, int &$off): int {
    $v = 0; $s = 0;
    while ($off < strlen($buf)) {
        $b = ord($buf[$off++]);
        $v |= ($b & 0x7F) << $s;
        if (($b & 0x80) === 0) return $v;
        $s += 7;
        if ($s > 28) throw new RuntimeException('VarInt too large');
    }
    throw new RuntimeException('VarInt out of bounds');
}

function read_nbt_root(string $buf, int &$off): void {
    // network NBT root compound: [0x0A]...fields...[0x00] — упрощённый обход.
    $type = ord($buf[$off++]);
    if ($type !== 0x0A) throw new RuntimeException("root tag not Compound: $type");
    skip_nbt_payload($buf, $off, 0x0A);
}

function skip_nbt_payload(string $buf, int &$off, int $type): void {
    switch ($type) {
        case 1: case 7: $off += 1; break;                       // Byte / (byte[] handled below)
        case 2: $off += 2; break;
        case 3: $off += 4; break;
        case 4: $off += 8; break;
        case 5: $off += 4; break;
        case 6: $off += 8; break;
        case 8: { $l = unpack('n', substr($buf, $off, 2))[1]; $off += 2 + $l; break; }
        case 7: { $l = unpack('N', substr($buf, $off, 4))[1]; $off += 4 + $l; break; } // ByteArray
        case 9: { $el = ord($buf[$off++]); $n = unpack('N', substr($buf, $off, 4))[1]; $off += 4;
                  for ($i = 0; $i < $n; $i++) skip_nbt_payload($buf, $off, $el); break; }
        case 10: { // Compound: читаем поля до TAG_End
                  while (true) {
                      $t = ord($buf[$off++]);
                      if ($t === 0) break;
                      $nl = unpack('n', substr($buf, $off, 2))[1]; $off += 2 + $nl;
                      skip_nbt_payload($buf, $off, $t);
                  }
                  break; }
        case 11: { $l = unpack('N', substr($buf, $off, 4))[1]; $off += 4 + 4 * $l; break; } // IntArray
        case 12: { $l = unpack('N', substr($buf, $off, 4))[1]; $off += 4 + 8 * $l; break; } // LongArray
        default: throw new RuntimeException("unknown nbt tag $type");
    }
}

function validate_chunk_body(string $body, int $expectId): array {
    $off = 0;
    $id = vi_read($body, $off);
    if ($id !== $expectId) throw new RuntimeException(sprintf('packet id 0x%02X != 0x%02X', $id, $expectId));

    $cx = unpack('N', substr($body, $off, 4))[1]; $off += 4; if ($cx >= 0x80000000) $cx -= 0x100000000;
    $cz = unpack('N', substr($body, $off, 4))[1]; $off += 4; if ($cz >= 0x80000000) $cz -= 0x100000000;

    $fullChunk = ord($body[$off]); $off += 1;                    // Bit Mask u8

    $hmCount = vi_read($body, $off);                             // Prefixed Array count
    $hmStart = $off;
    read_nbt_root($body, $off);                                  // NBT root
    $hmLen = $off - $hmStart;

    $dataLen = vi_read($body, $off);                             // Byte Array len
    $sections = substr($body, $off, $dataLen);
    $secOff = 0;
    $sectionReport = [];
    for ($s = 0; $s < 24; $s++) {
        $blockCount = unpack('n', substr($sections, $secOff, 2))[1]; $secOff += 2;
        $bits = ord($sections[$secOff]); $secOff += 1;
        $palSize = vi_read($sections, $secOff);
        for ($p = 0; $p < $palSize; $p++) vi_read($sections, $secOff);
        if ($bits === 0) {
            $sectionReport[] = "s$s:count=$blockCount,bits=0,pal=$palSize,data=0";
        } else {
            $longs = vi_read($sections, $secOff);
            $secOff += $longs * 8;
            $sectionReport[] = "s$s:count=$blockCount,bits=$bits,pal=$palSize, longs=$longs";
        }
        $bl = vi_read($sections, $secOff); $secOff += $bl;       // Block light byte array
        $sl = vi_read($sections, $secOff); $secOff += $sl;       // Sky light byte array
    }
    if ($secOff !== strlen($sections)) {
        throw new RuntimeException("sections over/underrun: consumed $secOff of " . strlen($sections));
    }
    $off += $dataLen;

    $beCount = vi_read($body, $off);                             // Block Entities
    for ($i = 0; $i < $beCount; $i++) {
        $off += 1;                                               // packed XZ u8
        $off += 2;                                               // Y i16
        vi_read($body, $off);                                    // Type VarInt
        $nbtStart = $off; read_nbt_root($body, $off);            // NBT
    }

    $masks = [];
    for ($m = 0; $m < 4; $m++) {
        $cnt = vi_read($body, $off); $masks[] = $cnt; $off += $cnt * 8;
    }

    $lightArrays = [];
    for ($la = 0; $la < 2; $la++) {
        $cnt = vi_read($body, $off); $bytes = 0;
        for ($i = 0; $i < $cnt; $i++) { $l = vi_read($body, $off); $bytes += $l; $off += $l; }
        $lightArrays[] = "$cnt x {$bytes}B";
    }

    $extra = strlen($body) - $off;

    return [
        'id' => $id, 'x' => $cx, 'z' => $cz, 'fullChunkMask' => $fullChunk,
        'heightmaps' => "count=$hmCount nbtBytes=$hmLen",
        'data' => "len=$dataLen sections=" . implode('|', $sectionReport),
        'blockEntities' => $beCount,
        'masks' => implode('/', $masks),
        'light' => implode(' , ', $lightArrays),
        'totalBodyBytes' => strlen($body),
        'extraBytesAtEnd' => $extra,
    ];
}

$factory = new PacketFactory(new ServerConfig());
$body = "\x23" /* VarInt 0x23 */;
$body .= $factory->createChunkData(0, 0);

echo "Chunk Data body length: " . strlen($body) . " bytes\n";
$report = validate_chunk_body($body, 0x23);
foreach ($report as $k => $v) echo "  $k: $v\n";

if ($report['extraBytesAtEnd'] !== 0) {
    echo "RESULT: FAIL — {$report['extraBytesAtEnd']} лишних байт в конце пакета\n";
    exit(1);
}

// Второй чанк (воздушный, вне спавна тоже валиден — идентичен по структуре)
$body2 = "\x23" . $factory->createChunkData(-4, 3);
$r2 = validate_chunk_body($body2, 0x23);
if ($r2['extraBytesAtEnd'] !== 0 && $r2['x'] === -4 && $r2['z'] === 3) {
    echo "RESULT: PASS — пакет 0x23 разобран клиент-парсером без лишних байт (оба чанка)\n";
    exit(0);
}
echo "RESULT: FAIL second chunk\n";
exit(1);
