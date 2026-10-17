<?php
// Проверка: все PacketId::CONST, используемые в server.php, определены в классе PacketId.
$src = file_get_contents(__DIR__ . '/server.php');
preg_match_all('/PacketId::([A-Z_0-9]+)/', $src, $m);
$used = array_unique($m[1]);
preg_match('/final class PacketId\s*\{(.*?)\n\}/s', $src, $c);
preg_match_all('/public const ([A-Z_0-9]+)/', $c[1], $d);
$defined = array_unique($d[1]);
$missing = array_diff($used, $defined);
echo "USED=" . count($used) . " DEFINED=" . count($defined) . "\n";
if (count($missing) === 0) {
    echo "RESULT: ALL CONSTANTS DEFINED OK\n";
    exit(0);
}
echo "MISSING: " . implode(', ', $missing) . "\n";
exit(1);
