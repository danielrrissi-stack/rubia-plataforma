<?php
declare(strict_types=1);

// Uso: php tools/import_pof.php [--sql-only]
//   Le data/pof/medidas.csv, gera database/seeds/food_2_pof_measures.sql e (sem --sql-only) carrega no banco
//   configurado (APP_ENV=test para o banco de testes). Idempotente; exige a base TACO ja carregada.
require __DIR__ . '/../app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Install\SeedLoader;
use App\Nutrition\PofMeasureImporter;

$rows = PofMeasureImporter::parseCsv(APP_ROOT . '/data/pof/medidas.csv');
$seed = APP_ROOT . '/database/seeds/food_2_pof_measures.sql';
file_put_contents($seed, PofMeasureImporter::seedSql($rows));
echo 'Gerado database/seeds/food_2_pof_measures.sql (' . count($rows) . " medidas).\n";

if (!in_array('--sql-only', $argv, true)) {
    $db = new Database((array) Config::get('db', []));
    SeedLoader::loadFile($db, $seed);
    echo "food_measures atualizada.\n";
}
