<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Core\App;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Session;
use PHPUnit\Framework\TestCase;

final class InstallRouteTest extends TestCase
{
    protected function tearDown(): void
    {
        Config::reset();
        Session::reset();
    }

    public function test_installed_system_returns_404_for_instalar(): void
    {
        $this->assertSame(404, App::create()->handle(new Request('GET', '/instalar'))->status);
        
    }

    public function test_not_installed_redirects_and_renders_form(): void
    {
        Config::set([]);
        $res = App::create()->handle(new Request('GET', '/login'));
        $this->assertSame(302, $res->status);
        $this->assertSame('/instalar', $res->headers['Location']);

        $res = App::create()->handle(new Request('GET', '/instalar', [], [], [], ['HTTP_HOST' => 'rubia.med.br', 'HTTPS' => 'on']));
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('https://rubia.med.br', $res->body);
        $this->assertStringContainsString('Sem ela não é possível restaurar backups.', $res->body);
    }

    public function test_invalid_post_keeps_values_but_not_passwords(): void
    {
        Config::set([]);
        $post = ['_csrf' => Csrf::token(), 'admin_name' => 'Maria Teste', 'admin_email' => 'ruim',
            'admin_password' => 'segredo-super-1', 'admin_password2' => 'x', 'db_host' => 'localhost'];
        $res = App::create()->handle(new Request('POST', '/instalar', [], $post));
        $this->assertSame(422, $res->status);
        $this->assertStringContainsString('Maria Teste', $res->body);
        $this->assertStringNotContainsString('segredo-super-1', $res->body);
        $this->assertStringContainsString('Informe um e-mail válido.', $res->body);
    }

    public function test_lock_without_config_is_error_not_installer(): void
    {
        $lock = sys_get_temp_dir() . '/rubia_lock_' . bin2hex(random_bytes(4));
        file_put_contents($lock, '');
        try {
            Config::set([]);
            Config::setLockFile($lock);
            foreach (['/instalar', '/login'] as $path) {
                $res = App::create()->handle(new Request('GET', $path));
                $this->assertSame(500, $res->status);
                $this->assertStringContainsString('Configuração indisponível', $res->body);
            }
        } finally {
            unlink($lock);
        }
    }

    public function test_successful_install_shows_private_key_with_no_store(): void
    {
        $db = new \App\Core\Database((array) Config::get('db'));
        $tmp = sys_get_temp_dir() . '/rubia_http_' . bin2hex(random_bytes(4));
        mkdir($tmp, 0777, true);
        $drop = function () use ($db): void {
            $pdo = $db->pdo();
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            foreach ($pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN) as $t) {
                $pdo->exec('DROP TABLE `' . $t . '`');
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        };
        $drop();
        $c = (array) Config::get('db');
        try {
            \App\Install\InstallController::$installerFactory = fn () => new \App\Install\Installer($tmp . '/config.php', APP_ROOT, $tmp . '/storage');
            Config::set([]);
            $post = ['_csrf' => Csrf::token(), 'db_host' => $c['host'], 'db_name' => $c['name'], 'db_user' => $c['user'], 'db_pass' => $c['pass'],
                'base_url' => 'https://rubia.med.br', 'mail_from' => 'a@rubia.med.br', 'admin_name' => 'Dra Teste',
                'admin_email' => 'dra@example.test', 'admin_crm' => 'CRM 1', 'admin_password' => 'senha-longa-123',
                'admin_password2' => 'senha-longa-123', 'backup_pass' => 'backup-senha-forte-1', 'backup_pass2' => 'backup-senha-forte-1'];
            $res = App::create()->handle(new Request('POST', '/instalar', [], $post));
            $this->assertSame(200, $res->status);
            $this->assertStringContainsString('BEGIN ENCRYPTED PRIVATE KEY', $res->body);
            $this->assertStringContainsString('no-store', $res->headers['Cache-Control']);
            $this->assertSame('no-cache', $res->headers['Pragma']);
        } finally {
            \App\Install\InstallController::$installerFactory = null;
            \Tests\TestSchema::rebuild($db);
            foreach ([$tmp . '/storage/keys', $tmp . '/storage/uploads', $tmp . '/storage/backups', $tmp . '/storage/logs', $tmp . '/storage', $tmp] as $d) {
                foreach (glob($d . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
                    is_file($f) && @unlink($f);
                }
                @rmdir($d);
            }
        }
    }
}
