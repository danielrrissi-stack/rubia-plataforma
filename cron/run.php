<?php
declare(strict_types=1);

// Executado pelo Cron do cPanel a cada 5 minutos: php /caminho/cron/run.php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('403 Forbidden');
}

require __DIR__ . '/../app/bootstrap.php';

use App\Access\PatientScope;
use App\Agenda\AgendaAccess;
use App\Agenda\AgendaMailer;
use App\Agenda\AppointmentRepository;
use App\Agenda\ReminderRound;
use App\Agenda\ReminderText;
use App\Agenda\ScheduleRepository;
use App\Audit\AuditLog;
use App\Backup\BackupService;
use App\Backup\Dumper;
use App\Core\Config;
use App\Core\Container;
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

// rodada da vespera: os e-mails colocados na fila saem na passada seguinte (5 min)
try {
    $db ??= new Database((array) Config::get('db'));
    $mailer = new Mailer($db, (array) Config::get('mail', []));
    $reminderText = new ReminderText($db);
    $audit = new AuditLog($db);
    $access = new AgendaAccess($db, new PatientScope($db));
    $appointments = new AppointmentRepository($db, $audit, new ScheduleRepository($db, $audit), $access);
    (new ReminderRound($db, $appointments, $reminderText, new AgendaMailer($db, $mailer, $reminderText), $mailer, $access))->runIfDue(date('Y-m-d H:i'));
} catch (Throwable $e) {
    fwrite(STDERR, '[cron] agenda: ' . $e->getMessage() . PHP_EOL);
    $failed = true;
}

// continuacao dos planos por dias: a partir das 06:00, uma vez por dia; o e-mail sai na passada seguinte
try {
    Container::planContinuation()->runIfDue(date('Y-m-d H:i'));
} catch (Throwable $e) {
    fwrite(STDERR, '[cron] continuacao: ' . $e->getMessage() . PHP_EOL);
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
