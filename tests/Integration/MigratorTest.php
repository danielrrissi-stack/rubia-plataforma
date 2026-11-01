<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Core\Database;
use App\Core\Migrator;
use PHPUnit\Framework\TestCase;

final class MigratorTest extends TestCase
{
    protected function tearDown(): void
    {
        // recoloca as referencias OMS (o teste derruba o schema) para os demais testes
        \Tests\TestSchema::restore(new Database((array) Config::get('db')));
    }

    public function test_migrates_all_and_is_idempotent(): void
    {
        $db = new Database((array) Config::get('db'));
        foreach (array_column($db->all('SHOW TABLES'), 'Tables_in_rubia_test') as $t) {
            // limpa para garantir banco vazio (FKs desligadas durante o drop)
            $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
            $db->pdo()->exec('DROP TABLE `' . $t . '`');
            $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
        }

        $migrator = new Migrator($db, APP_ROOT . '/database/migrations');
        $this->assertCount(7, $migrator->migrate());
        $this->assertSame([], $migrator->migrate());

        $tables = array_column($db->all('SHOW TABLES'), 'Tables_in_rubia_test');
        foreach (['users', 'audit_log', 'clinical_entries', 'measurements', 'who_lms', 'energy_goals'] as $t) {
            $this->assertContains($t, $tables);
        }
    }
}
