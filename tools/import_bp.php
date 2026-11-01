<?php
declare(strict_types=1);

// Uso: php tools/import_bp.php [--from-dat] [--sql-only]
//   --from-dat: regenera data/bp/aap2017.csv a partir dos arquivos brutos data/bp/flynn2017_bp_*.dat.
//   Le data/bp/aap2017.csv, gera database/seeds/bp_reference.sql e (sem --sql-only) grava
//   bp_reference no banco configurado (APP_ENV=test para o banco de testes). Idempotente.
require __DIR__ . '/../app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Growth\BpReferenceImporter;
use App\Install\SeedLoader;

$dir = APP_ROOT . '/data/bp';
$csvPath = $dir . '/aap2017.csv';

if (in_array('--from-dat', $argv, true)) {
    $rows = array_merge(
        BpReferenceImporter::parseDat($dir . '/flynn2017_bp_boys.dat', 1),
        BpReferenceImporter::parseDat($dir . '/flynn2017_bp_girls.dat', 2),
    );
    file_put_contents($csvPath, BpReferenceImporter::toCsv($rows));
    echo 'Gerado data/bp/aap2017.csv (' . count($rows) . " linhas).\n";
}

$rows = BpReferenceImporter::parseCsv($csvPath);
$seed = APP_ROOT . '/database/seeds/bp_reference.sql';
file_put_contents($seed, BpReferenceImporter::seedSql($rows));
echo 'Gerado database/seeds/bp_reference.sql (' . count($rows) . " linhas).\n";

if (!in_array('--sql-only', $argv, true)) {
    $db = new Database((array) Config::get('db', []));
    SeedLoader::loadFile($db, $seed);
    echo "bp_reference atualizada.\n";
}
