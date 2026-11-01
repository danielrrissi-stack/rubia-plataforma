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
}
