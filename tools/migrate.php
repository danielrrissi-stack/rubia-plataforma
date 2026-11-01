<?php
declare(strict_types=1);

// Uso: php tools/migrate.php   (APP_ENV=test para o banco de testes)
require __DIR__ . '/../app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Migrator;

$migrator = new Migrator(new Database((array) Config::get('db', [])), APP_ROOT . '/database/migrations');
$applied = $migrator->migrate();
echo $applied === [] ? "Nada a migrar.\n" : "Aplicadas:\n  " . implode("\n  ", $applied) . "\n";
