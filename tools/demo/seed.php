<?php
declare(strict_types=1);

// Monta a clinica ficticia do manual. Uso (Git Bash): APP_ENV=demo php tools/demo/seed.php
// So roda no banco local rubia_demo (ver DemoSeed::assertSafeTarget).
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('403 Forbidden');
}
if ((getenv('APP_ENV') ?: '') !== 'demo') {
    fwrite(STDERR, "Rode com APP_ENV=demo (usa config/config.demo.php).\n");
    exit(1);
}
require __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/DemoSeed.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Migrator;
use App\Install\SeedLoader;
use Tools\Demo\DemoSeed;

$cfg = (array) Config::get('db', []);
try {
    DemoSeed::assertSafeTarget($cfg);
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
$db = new Database($cfg);
(new Migrator($db, APP_ROOT . '/database/migrations'))->migrate();
SeedLoader::loadDir($db, APP_ROOT . '/database/seeds');
$ids = DemoSeed::run($db, date('Y-m-d'));

$dir = rtrim((string) Config::get('storage_path', APP_ROOT . '/storage'), '/\\');
@mkdir($dir, 0775, true);
$out = APP_ROOT . '/storage/demo';
@mkdir($out, 0775, true);
file_put_contents($out . '/ids.json', json_encode($ids, JSON_PRETTY_PRINT));
file_put_contents($out . '/logins.json', json_encode(DemoSeed::LOGINS, JSON_PRETTY_PRINT));
echo "Clinica ficticia pronta em " . DemoSeed::DB_NAME . ".\nContas: " . implode(', ', DemoSeed::LOGINS) . "\nIds em storage/demo/ids.json\n";
