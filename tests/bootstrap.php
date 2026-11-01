<?php
declare(strict_types=1);

putenv('APP_ENV=test');
$_ENV['APP_ENV'] = 'test';

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/DbTestCase.php';

// Recria o schema de rubia_test uma vez por execucao: derruba todas as tabelas e migra.
(static function (): void {
    $db = new App\Core\Database((array) App\Core\Config::get('db'));
    $pdo = $db->pdo();
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $pdo->exec('DROP TABLE `' . $table . '`');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    (new App\Core\Migrator($db, APP_ROOT . '/database/migrations'))->migrate();
})();
