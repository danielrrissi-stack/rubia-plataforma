<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use PHPUnit\Framework\TestCase;

final class AppTest extends TestCase
{
    public function testUnknownRouteRendersStyled404(): void
    {
        $res = App::create()->handle(new Request('GET', '/nada'));
        $this->assertSame(404, $res->status);
        $this->assertStringContainsString('Página não encontrada', $res->body);
        $this->assertStringContainsString('/assets/css/app.css', $res->body);
    }

    public function testPostWithoutTokenShowsFriendly419(): void
    {
        $router = new Router();
        $router->post('/x', fn () => Response::html('ok'));
        $res = (new App($router))->handle(new Request('POST', '/x'));
        $this->assertSame(419, $res->status);
        $this->assertStringContainsString('Sua sessão expirou. Recarregue a página e tente novamente.', $res->body);
    }

    public function testWrongMethodShows405(): void
    {
        $router = new Router();
        $router->get('/x', fn () => Response::html('ok'));
        $this->assertSame(405, (new App($router))->handle(new Request('POST', '/x'))->status);
    }

    public function testExceptionBecomes500WithoutDetailsAndIsLogged(): void
    {
        $router = new Router();
        $router->get('/boom', fn () => throw new \RuntimeException('segredo-interno'));
        $res = (new App($router))->handle(new Request('GET', '/boom'));
        $this->assertSame(500, $res->status);
        $this->assertStringNotContainsString('segredo-interno', $res->body);
        $log = (string) file_get_contents(APP_ROOT . '/storage/logs/app.log');
        $this->assertStringContainsString('segredo-interno', $log);
    }
}
