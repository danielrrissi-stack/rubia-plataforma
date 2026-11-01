<?php
declare(strict_types=1);

// Executado pelo Cron do cPanel a cada 5 minutos: php /caminho/cron/run.php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('403 Forbidden');
}

require __DIR__ . '/../app/bootstrap.php';

use App\Backup\BackupService;
use App\Backup\Dumper;
use App\Core\Config;
use App\Core\Database;
use App\Core\Mailer;

$failed = false;
$db = null;

try {
    $db = new Database((array) Config::get('db'));
    (new Mailer($db, (array) Config::get('mail', [])))->sendPending();
} catch (Throwable $e) {
    fwrite(STDERR, '[cron] e-mail: ' . $e->getMessage() . PHP_EOL);
    $failed = true;
}

try {
    $db ??= new Database((array) Config::get('db'));
    (new BackupService($db, new Dumper($db), ['storage_path' => (string) Config::get('storage_path')]))->runIfDue();
} catch (Throwable $e) {
    fwrite(STDERR, '[cron] backup: ' . $e->getMessage() . PHP_EOL);
    $failed = true;
}

exit($failed ? 1 : 0);
