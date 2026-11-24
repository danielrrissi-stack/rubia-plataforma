<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('403 Forbidden');
}

/**
 * Regra de exclusao do pacote de release (usada por tools/build_release.php e testada em DemoSeedTest).
 * $rel = caminho relativo a raiz, com "/".
 */
function release_is_excluded(string $rel): bool
{
    /** Caminhos que nunca entram no pacote. Terminando em / = pasta inteira. */
    $excludePrefixes = [
        'tests/', 'docs/', '.git/', '.superpowers/', '.phpunit.cache/', 'dist/',
        'tools/phpunit.phar', 'tools/make_icons.php',
        // clinica ficticia, fotos e PDFs do manual: so na maquina de desenvolvimento
        'tools/demo/', 'tools/manual_shots.mjs', 'tools/manual_shots_lib.js', 'tools/manual_shots_list.php', 'tools/manual_pdf.php',
        'config/config.php', 'config/config.test.php', 'config/config.demo.php', 'config/config.demo.example.php', 'config/.install.lock',
        'modelo_papel/', 'o que fazer.txt', 'phpunit.xml', '.gitignore', '.gitattributes',
    ];
    /** Em storage/ so entram estes arquivos (a pasta precisa existir, vazia e protegida). */
    $storageKeep = ['storage/.htaccess', 'storage/.gitkeep'];
    if (str_starts_with($rel, 'storage/')) {
        return !preg_match('#^storage/(.*/)?\.gitkeep$#', $rel) && !in_array($rel, $storageKeep, true);
    }
    foreach ($excludePrefixes as $p) {
        if ($rel === $p || (str_ends_with($p, '/') && str_starts_with($rel, $p))) {
            return true;
        }
    }
    return false;
}
