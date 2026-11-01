<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Backup\BackupKeys;
use App\Backup\BackupService;
use App\Backup\Dumper;
use Tests\DbTestCase;

final class BackupServiceTest extends DbTestCase
{
    private string $storage;
    private string $privatePem;
    private const PASS = 'senha-de-backup-teste';

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir() . '/rubia_bk_' . bin2hex(random_bytes(4));
        mkdir($this->storage . '/uploads/p1', 0777, true);
        file_put_contents($this->storage . '/uploads/p1/exame.txt', 'conteudo-do-exame');
        BackupKeys::generate(self::PASS, $this->storage . '/keys');
        $this->privatePem = (string) file_get_contents($this->storage . '/keys/backup_private.pem');
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->storage);
        parent::tearDown();
    }

    private function rrmdir(string $d): void
    {
        if (!is_dir($d)) {
            return;
        }
        foreach (scandir($d) as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            is_dir("$d/$f") ? $this->rrmdir("$d/$f") : @unlink("$d/$f");
        }
        @rmdir($d);
    }

    private function service(?callable $clock = null): BackupService
    {
        return new BackupService($this->db, new Dumper($this->db), ['storage_path' => $this->storage], $clock);
    }

    private function rbkFiles(): array
    {
        return glob($this->storage . '/backups/*.rbk') ?: [];
    }

    public function testCreateEncryptsAndRestoreRecoversDumpAndUploads(): void
    {
        $this->makeUser('medico', ['name' => 'Nome Sentinela Backup']);
        $path = $this->service()->create();
        $this->assertFileExists($path);
        $this->assertMatchesRegularExpression('/backup-\d{8}-\d{6}\.rbk$/', $path);

        $raw = (string) file_get_contents($path);
        $this->assertStringNotContainsString('INSERT INTO', $raw);
        $this->assertStringNotContainsString('Sentinela', $raw);
        $header = json_decode((string) strtok($raw, "\n"), true);
        $this->assertSame(1, $header['v']);
        $this->assertArrayHasKey('ek', $header);
        $this->assertArrayHasKey('iv', $header);
        $this->assertArrayHasKey('created', $header);

        $out = $this->storage . '/restored.zip';
        $this->service()->restoreToZip($path, $this->privatePem, self::PASS, $out);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($out) === true);
        $sql = (string) $zip->getFromName('database.sql');
        $this->assertStringContainsString('INSERT INTO', $sql);
        $this->assertStringContainsString('Nome Sentinela Backup', $sql);
        $this->assertStringContainsString('SET FOREIGN_KEY_CHECKS=0', $sql);
        $this->assertSame('conteudo-do-exame', $zip->getFromName('storage/uploads/p1/exame.txt'));
        $zip->close();
        // temporarios removidos
        $this->assertSame([], glob($this->storage . '/backups/*.tmp') ?: []);
    }

    public function testWrongPassphraseThrows(): void
    {
        $path = $this->service()->create();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Senha de backup incorreta');
        $this->service()->restoreToZip($path, $this->privatePem, 'errada', $this->storage . '/x.zip');
    }

    public function testCorruptedFileThrows(): void
    {
        $path = $this->service()->create();
        $raw = (string) file_get_contents($path);
        file_put_contents($path, substr($raw, 0, -40));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Arquivo de backup inválido ou corrompido');
        $this->service()->restoreToZip($path, $this->privatePem, self::PASS, $this->storage . '/x.zip');
    }

    public function testRunIfDueCreatesOnlyOnceAndAgainAfter24h(): void
    {
        $now = time();
        $clock = static function () use (&$now): int {
            return $now;
        };
        $svc = $this->service($clock);
        $this->assertNotNull($svc->runIfDue());
        $this->assertNull($svc->runIfDue());
        $this->assertCount(1, $this->rbkFiles());
        $this->assertNotNull($this->db->one("SELECT value FROM settings WHERE `key`='last_backup_at'"));

        $now += 23 * 3600;
        $this->assertNull($svc->runIfDue());
        $now += 2 * 3600;
        sleep(1); // nome do arquivo tem resolucao de segundos
        $this->assertNotNull($svc->runIfDue());
        $this->assertCount(2, $this->rbkFiles());
    }

    public function testRunIfDuePrunesFilesOlderThan30Days(): void
    {
        mkdir($this->storage . '/backups', 0777, true);
        $old = $this->storage . '/backups/backup-20200101-000000.rbk';
        file_put_contents($old, 'x');
        touch($old, time() - 31 * 86400);
        $this->assertNotNull($this->service()->runIfDue());
        $this->assertFileDoesNotExist($old);
        $this->assertCount(1, $this->rbkFiles());
    }

    public function testPruneRemovesStaleTmpAndKeepsFreshTmp(): void
    {
        mkdir($this->storage . '/backups', 0777, true);
        $stale = $this->storage . '/backups/dump-aaaa1111.tmp';
        $fresh = $this->storage . '/backups/zip-bbbb2222.tmp';
        file_put_contents($stale, 'x');
        file_put_contents($fresh, 'y');
        touch($stale, time() - 2 * 3600);
        $this->service()->runIfDue();
        $this->assertFileDoesNotExist($stale);
        $this->assertFileExists($fresh);
    }

    public function testRunIfDueReturnsNullWhileLockHeld(): void
    {
        mkdir($this->storage . '/backups', 0777, true);
        $fh = fopen($this->storage . '/backups/.lock', 'c');
        $this->assertTrue(flock($fh, LOCK_EX | LOCK_NB));
        try {
            $this->assertNull($this->service()->runIfDue());
            $this->assertCount(0, $this->rbkFiles());
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
        $this->assertNotNull($this->service()->runIfDue());
    }

    public function testDumpInsideOpenTransactionDoesNotCommitIt(): void
    {
        // DbTestCase mantem uma transacao aberta; o dump nao pode encerra-la (snapshot e pulado).
        $this->assertTrue($this->db->pdo()->inTransaction());
        (new Dumper($this->db))->dump($this->storage . '/d.sql');
        $this->assertTrue($this->db->pdo()->inTransaction());
    }
}
