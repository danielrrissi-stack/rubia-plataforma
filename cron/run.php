<?php
declare(strict_types=1);

// Executado pelo Cron do cPanel a cada 5 minutos: php /caminho/cron/run.php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('403 Forbidden');
}

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Mailer;

try {
    $db = new Database((array) Config::get('db'));
    (new Mailer($db, (array) Config::get('mail', [])))->sendPending();
} catch (Throwable $e) {
    fwrite(STDERR, '[cron] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
