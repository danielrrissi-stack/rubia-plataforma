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

    public function testIniBytesParsesShorthand(): void
    {
        $this->assertSame(8 * 1024 * 1024, Router::iniBytes('8M'));
        $this->assertSame(512 * 1024, Router::iniBytes('512k'));
        $this->assertSame(2 * 1024 * 1024 * 1024, Router::iniBytes(' 2G '));
        $this->assertSame(1000, Router::iniBytes('1000'));
        $this->assertSame(0, Router::iniBytes(''));
        $this->assertSame('8', Router::bytesToMb(8 * 1024 * 1024));
        $this->assertSame('0,5', Router::bytesToMb(512 * 1024));
    }

    public function testOversizedEmptyPostIs413BeforeCsrf(): void
    {
        $r = new Router();
        $r->post('/x', fn () => Response::html('ok'));
        $len = (string) (Router::iniBytes((string) ini_get('post_max_size')) + 1);
        try {
            $r->dispatch(new Request('POST', '/x', [], [], [], ['CONTENT_LENGTH' => $len]));
            $this->fail('esperava HttpException');
        } catch (HttpException $e) {
            $this->assertSame(413, $e->getCode());
        }
        // com corpo recebido (post nao vazio) segue para o CSRF
        try {
            $r->dispatch(new Request('POST', '/x', [], ['a' => '1'], [], ['CONTENT_LENGTH' => $len]));
            $this->fail('esperava HttpException');
        } catch (HttpException $e) {
            $this->assertSame(419, $e->getCode());
        }
    }
}
