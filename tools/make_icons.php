<?php
declare(strict_types=1);

/**
 * Gera os icones PWA (public/assets/icons/icon-192.png e icon-512.png) sem GD:
 * escreve PNG RGB direto com zlib. Uso: php tools/make_icons.php
 */
function png(int $size, callable $pixel): string
{
    $raw = '';
    for ($y = 0; $y < $size; $y++) {
        $raw .= "\0";
        for ($x = 0; $x < $size; $x++) {
            $raw .= $pixel($x, $y);
        }
    }
    $chunk = static fn(string $type, string $data): string
        => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    return "\x89PNG\r\n\x1a\n"
        . $chunk('IHDR', pack('NNCCCCC', $size, $size, 8, 2, 0, 0, 0))
        . $chunk('IDAT', gzcompress($raw, 9))
        . $chunk('IEND', '');
}

$dir = dirname(__DIR__) . '/public/assets/icons';
if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
}
$bg = "\x2f\x6b\x5f";   // --salvia
$leaf = "\xfa\xf6\xee"; // fundo do portal
foreach ([192, 512] as $size) {
    // Folha = intersecao de dois circulos (forma do .brand-mark), dentro da area segura "maskable".
    $c = $size / 2;
    $off = $size * 0.12;
    $r2 = ($size * 0.30) ** 2 * 2.6;
    $pixel = static function (int $x, int $y) use ($c, $off, $r2, $bg, $leaf): string {
        $a = ($x - ($c - $off)) ** 2 + ($y - ($c + $off)) ** 2 <= $r2;
        $b = ($x - ($c + $off)) ** 2 + ($y - ($c - $off)) ** 2 <= $r2;
        return $a && $b ? $leaf : $bg;
    };
    file_put_contents($dir . '/icon-' . $size . '.png', png($size, $pixel));
}
echo "Icones gerados em $dir\n";
