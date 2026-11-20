<?php
declare(strict_types=1);

// So pela linha de comando; pelo navegador, 403.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('403 Forbidden');
}

// Uso: php tools/build_diets.php [--sql-only]
//   Gera database/seeds/food_4_diet_library.sql a partir de data/dietas/cardapio.php (+ TACO/POF) e
//   (sem --sql-only) carrega no banco configurado. Idempotente.
require __DIR__ . '/../app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Diets\DietBuilder;
use App\Diets\DietSeed;
use App\Install\SeedLoader;

$diets = DietBuilder::fromDataDir(APP_ROOT)->all();
$seed = APP_ROOT . '/database/seeds/food_4_diet_library.sql';
file_put_contents($seed, DietSeed::sql($diets));
echo 'Gerado database/seeds/food_4_diet_library.sql (' . count($diets) . " dietas).\n";

if (!in_array('--sql-only', $argv, true)) {
    $db = new Database((array) Config::get('db', []));
    SeedLoader::loadFile($db, $seed);
    echo "diet_library atualizada.\n";
}
