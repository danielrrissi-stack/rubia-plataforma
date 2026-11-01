<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Database;
use App\Core\Migrator;
use App\Growth\WhoLmsImporter;

/** Schema do rubia_test: migracoes + dados de referencia (OMS). */
final class TestSchema
{
    public static function dropAll(Database $db): void
    {
        $pdo = $db->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN) as $table) {
            $pdo->exec('DROP TABLE `' . $table . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    /** Aplica migracoes pendentes e (re)carrega as referencias OMS. */
    public static function restore(Database $db): void
    {
        (new Migrator($db, APP_ROOT . '/database/migrations'))->migrate();
        WhoLmsImporter::loadSeed($db, APP_ROOT . '/database/seeds/who_lms.sql');
    }

    public static function rebuild(Database $db): void
    {
        self::dropAll($db);
        self::restore($db);
    }
}
