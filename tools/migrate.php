<?php
declare(strict_types=1);

// So pela linha de comando (Terminal do cPanel); pelo navegador, 403.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('403 Forbidden');
}

// Uso: php tools/migrate.php   (APP_ENV=test para o banco de testes)
require __DIR__ . '/../app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Migrator;
use App\Install\SeedLoader;

$migrator = new Migrator(new Database((array) Config::get('db', [])), APP_ROOT . '/database/migrations');
$applied = $migrator->migrate();
echo $applied === [] ? "Nada a migrar.\n" : "Aplicadas:\n  " . implode("\n  ", $applied) . "\n";

$db = new Database((array) Config::get('db', []));
$loaded = SeedLoader::loadDir($db, APP_ROOT . '/database/seeds');
echo $loaded === [] ? "Nenhum dado de referencia.\n" : "Dados de referencia carregados:\n  " . implode("\n  ", $loaded) . "\n";
