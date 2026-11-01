<?php
declare(strict_types=1);

/**
 * Gera dist/rubia-release-AAAAMMDD.zip com o que vai para o servidor (cPanel).
 * Uso: php tools/build_release.php
 */
if (PHP_SAPI !== 'cli') {
    exit('Execute pela linha de comando.');
}

$root = dirname(__DIR__);

/** Caminhos (relativos, com /) que nunca entram no pacote. Terminando em / = pasta inteira. */
$excludePrefixes = [
    'tests/', 'docs/', '.git/', '.superpowers/', '.phpunit.cache/', 'dist/',
    'tools/phpunit.phar', 'tools/make_icons.php',
    'config/config.php', 'config/config.test.php', 'config/.install.lock',
    'o que fazer.txt', 'phpunit.xml', '.gitignore', '.gitattributes',
];
/** Em storage/ só entram estes arquivos (a pasta precisa existir, vazia e protegida). */
$storageKeep = ['storage/.htaccess', 'storage/.gitkeep'];

$isExcluded = static function (string $rel) use ($excludePrefixes, $storageKeep): bool {
    if (str_starts_with($rel, 'storage/')) {
        return !preg_match('#^storage/(.*/)?\.gitkeep$#', $rel) && !in_array($rel, $storageKeep, true);
    }
    foreach ($excludePrefixes as $p) {
        if ($rel === $p || (str_ends_with($p, '/') && str_starts_with($rel, $p))) {
            return true;
        }
    }
    return false;
};

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
