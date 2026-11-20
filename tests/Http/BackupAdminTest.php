<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Backup\BackupKeys;
use App\Core\App;
use App\Core\Config;
use App\Core\Container;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use Tests\DbTestCase;

final class BackupAdminTest extends DbTestCase
{
    private string $storage;
    private array $saved;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir() . '/rubia_bkh_' . bin2hex(random_bytes(4));
        mkdir($this->storage . '/backups', 0777, true);
        BackupKeys::generate('pw', $this->storage . '/keys');
        Config::set(['storage_path' => $this->storage]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storage . '/{backups,keys}/*', GLOB_BRACE) ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->storage . '/backups');
        @rmdir($this->storage . '/keys');
        @rmdir($this->storage);
        Config::reset();
        parent::tearDown();
    }

    private function login(string $role, array $over = []): void
    {
        $u = $this->makeUser($role, $over + ['totp_enabled' => 1]);
        Container::auth()->completeLogin((int) $u['id'], new Request('POST', '/login'));
    }

    private function get(string $path): Response
    {
        return App::create()->handle(new Request('GET', $path));
    }

    public function testNonAdminIsForbidden(): void
    {
        $this->login('secretaria');
        $this->assertSame(403, $this->get('/admin/backups')->status);
    }

    public function testAdminListsGeneratesAndDownloadsWithAudit(): void
    {
        $this->login('medico', ['is_admin' => 1]);
        $res = App::create()->handle(new Request('POST', '/admin/backups/gerar', [], ['_csrf' => Csrf::token()], [], ['REMOTE_ADDR' => '127.0.0.1']));
        $this->assertSame(302, $res->status);
        $files = glob($this->storage . '/backups/*.rbk');
        $this->assertCount(1, $files);
        $name = basename($files[0]);

        $page = $this->get('/admin/backups');
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString($name, $page->body);

        $dl = $this->get('/admin/backups/' . $name);
        $this->assertSame(200, $dl->status);
        $this->assertSame($files[0], $dl->filePath);
        $row = $this->db->one("SELECT after_json FROM audit_log WHERE action='export' AND entity='backup' ORDER BY id DESC LIMIT 1");
        $this->assertSame(['arquivo' => $name], json_decode($row['after_json'], true));

        $key = $this->get('/admin/backups/chave-privada');
        $this->assertSame(200, $key->status);
        $this->assertStringEndsWith('backup_private.pem', $key->filePath);
    }

    public function testInvalidNameIs404(): void
    {
        $this->login('medico', ['is_admin' => 1]);
        $this->assertSame(404, $this->get('/admin/backups/..%2Fconfig.php')->status);
        $this->assertSame(404, $this->get('/admin/backups/backup-1.rbk')->status);
        $this->assertSame(404, $this->get('/admin/backups/backup-20200101-000000.rbk')->status);
    }
}
