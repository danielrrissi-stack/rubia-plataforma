<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private function router(): Router
    {
        $r = new Router();
        $r->get('/pacientes/{id:\d+}', fn (Request $q) => Response::html('id=' . $q->params['id']));
        return $r;
    }

    public function testMatchesParam(): void
    {
        $res = $this->router()->dispatch(new Request('GET', '/pacientes/12'));
        $this->assertSame('id=12', $res->body);
    }

    public function testNoMatchIs404(): void
    {
        try {
            $this->router()->dispatch(new Request('GET', '/pacientes/abc'));
            $this->fail('esperava HttpException');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getCode());
        }
    }

    public function testWrongMethodIs405(): void
    {
        try {
            $this->router()->dispatch(new Request('POST', '/pacientes/12'));
            $this->fail('esperava HttpException');
        } catch (HttpException $e) {
            $this->assertSame(405, $e->getCode());
        }
    }

    public function testMiddlewareRuns(): void
    {
        Router::middleware('teste', fn (Request $q, callable $next) => Response::html('bloqueado', 401));
        $r = new Router();
        $r->get('/x', fn () => Response::html('ok'), ['teste']);
        $this->assertSame(401, $r->dispatch(new Request('GET', '/x'))->status);
    }
}
