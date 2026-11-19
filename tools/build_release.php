<?php
declare(strict_types=1);

/**
 * Gera dist/rubia-release-AAAAMMDD.zip com o que vai para o servidor (cPanel).
 * Uso: php tools/build_release.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Execute pela linha de comando.');
}

$root = dirname(__DIR__);

require_once __DIR__ . '/release_filter.php';
$isExcluded = static fn (string $rel): bool => release_is_excluded($rel);

$distDir = $root . '/dist';
if (!is_dir($distDir) && !mkdir($distDir, 0775, true)) {
    fwrite(STDERR, "Não foi possível criar dist/\n");
    exit(1);
}
$zipPath = $distDir . '/rubia-release-' . date('Ymd') . '.zip';
if (is_file($zipPath)) {
    unlink($zipPath);
}

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "Não foi possível criar $zipPath\n");
    exit(1);
}

$files = [];
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY,
);
foreach ($it as $f) {
    /** @var SplFileInfo $f */
    if (!$f->isFile()) {
        continue;
    }
    $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
    if ($isExcluded($rel)) {
        continue;
    }
    $files[] = $rel;
}
sort($files);
foreach ($files as $rel) {
    $zip->addFile($root . '/' . $rel, $rel);
}
// Pastas de armazenamento vazias (o app cria as demais sob demanda).
if (!in_array('storage/.gitkeep', $files, true)) {
    $zip->addEmptyDir('storage');
}
$zip->close();

printf("Pacote: %s\nArquivos: %d\nTamanho: %.2f MB\n", $zipPath, count($files), filesize($zipPath) / 1048576);
