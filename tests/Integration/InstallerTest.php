<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Core\Database;
use App\Install\Installer;
use PHPUnit\Framework\TestCase;

/** Roda DDL: nao usa a transacao do DbTestCase; limpa o schema antes e depois. */
final class InstallerTest extends TestCase
{
    private Database $db;
    private string $tmp;

    protected function setUp(): void
    {
        $this->db = new Database((array) Config::get('db'));
        $this->dropAll();
        $this->tmp = sys_get_temp_dir() . '/rubia_inst_' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        \Tests\TestSchema::rebuild($this->db);
        $this->rrmdir($this->tmp);
    }

    private function dropAll(): void
    {
        $pdo = $this->db->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN) as $t) {
            $pdo->exec('DROP TABLE `' . $t . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    private function rrmdir(string $d): void
    {
        foreach (glob($d . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (in_array(basename($f), ['.', '..'], true)) {
                continue;
            }
            is_dir($f) ? $this->rrmdir($f) : @unlink($f);
        }
        @rmdir($d);
    }

    private function input(array $over = []): array
    {
        $c = (array) Config::get('db');
        return array_merge([
            'db_host' => $c['host'], 'db_name' => $c['name'], 'db_user' => $c['user'], 'db_pass' => $c['pass'],
            'base_url' => 'https://rubia.med.br', 'mail_from' => 'nao-responda@rubia.med.br',
            'admin_name' => 'Dra. Rubia Teste', 'admin_email' => 'Rubia@Example.TEST', 'admin_crm' => 'CRM-SP 12345',
            'admin_password' => 'senha-longa-123', 'admin_password2' => 'senha-longa-123',
            'backup_pass' => 'backup-senha-forte-1', 'backup_pass2' => 'backup-senha-forte-1',
        ], $over);
    }

    private function installer(): Installer
    {
        return new Installer($this->tmp . '/config.php', APP_ROOT, $this->tmp . '/storage');
    }

    public function test_install_creates_admin_keys_and_term(): void
    {
        $inst = $this->installer();
        $inst->run($this->input());

        $u = $this->db->one("SELECT * FROM users WHERE email = 'rubia@example.test'");
        $this->assertNotNull($u);
        $this->assertSame('medico', $u['role']);
        $this->assertSame(1, (int) $u['is_admin']);
        $this->assertSame('CRM-SP 12345', $u['professional_register']);
        $this->assertSame(0, (int) $u['totp_enabled']);
        $this->assertTrue(password_verify('senha-longa-123', $u['password_hash']));

        $pub = $this->tmp . '/storage/keys/backup_public.pem';
        $priv = $this->tmp . '/storage/keys/backup_private.pem';
        $this->assertFileExists($pub);
        $this->assertFileExists($priv);
        $pem = (string) file_get_contents($priv);
        $this->assertFalse(@openssl_pkey_get_private($pem));
        $this->assertNotFalse(openssl_pkey_get_private($pem, 'backup-senha-forte-1'));
        $this->assertSame($pem, $inst->privateKeyPem());
        foreach (['uploads', 'backups', 'logs'] as $d) {
            $this->assertDirectoryExists($this->tmp . '/storage/' . $d);
        }

        $t = $this->db->one("SELECT * FROM consent_terms WHERE version = '1.0'");
        $this->assertNotNull($t);
        $this->assertSame(1, (int) $t['active']);
        $this->assertStringContainsString('LGPD', $t['body_html']);
        $this->assertNotNull($this->db->one("SELECT value FROM settings WHERE `key` = 'installed_at'"));
        // Dados de referencia carregados de database/seeds/*.sql.
        $this->assertSame(17824, (int) $this->db->one('SELECT COUNT(*) AS n FROM who_lms')['n']);
        $this->assertSame(714, (int) $this->db->one('SELECT COUNT(*) AS n FROM bp_reference')['n']);

        $cfg = require $this->tmp . '/config.php';
        $this->assertSame($this->tmp . '/storage', $cfg['storage_path']);
        $this->assertArrayNotHasKey('force_https', $cfg); // HTTPS e sempre imposto pelo .htaccess
        $this->assertSame('Dra. Rubia', $cfg['mail']['from_name']);
        $this->assertSame(30, $cfg['session']['staff_idle_minutes']);
        $this->assertSame((require APP_ROOT . '/config/config.example.php') ? array_keys(require APP_ROOT . '/config/config.example.php') : [], array_keys($cfg));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('já instalado');
        $this->installer()->run($this->input());
    }

    public function test_validation_errors(): void
    {
        $errors = $this->installer()->validate($this->input([
            'admin_email' => 'x', 'admin_password2' => 'outra', 'backup_pass' => 'curta', 'backup_pass2' => 'curta', 'db_name' => '',
        ]));
        foreach (['admin_email', 'admin_password', 'backup_pass', 'db_name'] as $k) {
            $this->assertArrayHasKey($k, $errors);
        }
        $this->assertSame([], $this->installer()->validate($this->input()));
    }

    public function test_refuses_when_lock_exists_and_keeps_lock_after_install(): void
    {
        file_put_contents($this->tmp . '/.install.lock', '');
        try {
            $this->installer()->run($this->input());
            $this->fail('deveria recusar');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('já realizada ou em andamento', $e->getMessage());
        }
        unlink($this->tmp . '/.install.lock');

        $this->installer()->run($this->input());
        $this->assertFileExists($this->tmp . '/.install.lock');
        $this->assertFileExists($this->tmp . '/config.php');
    }

    public function test_failure_before_commit_releases_lock(): void
    {
        try {
            $this->installer()->run($this->input(['db_pass' => 'senha-errada']));
        } catch (\RuntimeException) {
        }
        $this->assertFileDoesNotExist($this->tmp . '/.install.lock');
        $this->assertFileDoesNotExist($this->tmp . '/config.php');
    }
}
