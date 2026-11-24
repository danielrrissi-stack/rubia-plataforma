<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Csrf;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    public function testPostWithoutTokenIs419(): void
    {
        try {
            Csrf::check(new Request('POST', '/x', [], []));
            $this->fail('esperava HttpException');
        } catch (HttpException $e) {
            $this->assertSame(419, $e->getCode());
        }
    }

    public function testPostWithValidTokenPasses(): void
    {
        Csrf::check(new Request('POST', '/x', [], ['_csrf' => Csrf::token()]));
        $this->addToAssertionCount(1);
    }

    public function testRouterAppliesCsrfToEveryPost(): void
    {
        $r = new Router();
        $r->post('/x', fn () => Response::html('ok'));
        try {
            $r->dispatch(new Request('POST', '/x'));
            $this->fail('esperava 419');
        } catch (HttpException $e) {
            $this->assertSame(419, $e->getCode());
        }
        $res = $r->dispatch(new Request('POST', '/x', [], ['_csrf' => Csrf::token()]));
        $this->assertSame('ok', $res->body);
    }
}
