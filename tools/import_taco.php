<?php
declare(strict_types=1);

// Uso: php tools/import_taco.php [--sql-only]
//   Le data/taco/*.csv, gera database/seeds/food_1_taco.sql e (sem --sql-only) carrega no banco
//   configurado (APP_ENV=test para o banco de testes). Idempotente; nao reativa alimentos desativados.
require __DIR__ . '/../app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Install\SeedLoader;
use App\Nutrition\TacoImporter;

$dir = APP_ROOT . '/data/taco';
$rows = TacoImporter::parse($dir . '/food.csv', $dir . '/nutrients.csv', $dir . '/categories.csv');
$seed = APP_ROOT . '/database/seeds/food_1_taco.sql';
file_put_contents($seed, TacoImporter::seedSql($rows));
echo 'Gerado database/seeds/food_1_taco.sql (' . count($rows) . " alimentos).\n";

if (!in_array('--sql-only', $argv, true)) {
    $db = new Database((array) Config::get('db', []));
    SeedLoader::loadFile($db, $seed);
    echo "foods atualizada.\n";
}
