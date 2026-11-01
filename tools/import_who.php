<?php
declare(strict_types=1);

// Uso: php tools/import_who.php [--sql-only]
//   Le data/who/*.txt, gera database/seeds/who_lms.sql e (sem --sql-only) grava
//   who_lms no banco configurado (APP_ENV=test para o banco de testes). Idempotente.
require __DIR__ . '/../app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Growth\WhoLmsImporter;

$rows = WhoLmsImporter::parseDir(APP_ROOT . '/data/who');
$statements = WhoLmsImporter::statements($rows);

$seedDir = APP_ROOT . '/database/seeds';
if (!is_dir($seedDir) && !mkdir($seedDir, 0775, true)) {
    fwrite(STDERR, "Nao foi possivel criar $seedDir\n");
    exit(1);
}
file_put_contents($seedDir . '/who_lms.sql', WhoLmsImporter::seedSql($statements, count($rows)));
echo 'Gerado database/seeds/who_lms.sql (' . count($rows) . " linhas).\n";

if (!in_array('--sql-only', $argv, true)) {
    $db = new Database((array) Config::get('db', []));
    $db->transaction(static fn() => WhoLmsImporter::execute($db, $statements));
    echo "who_lms atualizada.\n";
}
