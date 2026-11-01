<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Database;
use App\Core\Migrator;
use App\Install\SeedLoader;

/** Schema do rubia_test: migracoes + dados de referencia (database/seeds: OMS e PA AAP 2017). */
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

    /** Aplica migracoes pendentes e (re)carrega os dados de referencia (mesmo carregador do instalador). */
    public static function restore(Database $db): void
    {
        (new Migrator($db, APP_ROOT . '/database/migrations'))->migrate();
        SeedLoader::loadDir($db, APP_ROOT . '/database/seeds');
    }

    public static function rebuild(Database $db): void
    {
        self::dropAll($db);
        self::restore($db);
    }
}
