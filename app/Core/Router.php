<?php
declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** @var array<string,callable> */
    private static array $middleware = [];
    /** @var list<array{method:string,regex:string,handler:array|callable,mw:array}> */
    private array $routes = [];

    /** Registra middleware nomeado: fn(Request, callable $next): Response. */
    public static function middleware(string $name, callable $fn): void
    {
        self::$middleware[$name] = $fn;
    }

    public function get(string $pattern, array|callable $handler, array $middleware = []): void
    {
        $this->add('GET', $pattern, $handler, $middleware);
    }

    public function post(string $pattern, array|callable $handler, array $middleware = []): void
    {
        $this->add('POST', $pattern, $handler, $middleware);
    }

    private function add(string $method, string $pattern, array|callable $handler, array $mw): void
    {
        $regex = preg_replace_callback('/\{(\w+)(?::([^}]+))?\}/', static function (array $m): string {
            return '(?P<' . $m[1] . '>' . ($m[2] ?? '[^/]+') . ')';
        }, $pattern);
        $this->routes[] = [
            'method' => $method,
            'regex' => '#^' . str_replace('#', '\#', (string) $regex) . '$#',
            'handler' => $handler,
            'mw' => $mw,
        ];
    }

    public function dispatch(Request $request): Response
    {
        $pathMatched = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path(), $m)) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== $request->method()) {
                continue;
            }
            foreach ($m as $k => $v) {
                if (is_string($k)) {
                    $request->params[$k] = $v;
                }
            }
            $names = $route['mw'];
            if ($request->method() === 'POST' && !in_array('csrf', $names, true)) {
                array_unshift($names, 'csrf');
            }
            $core = fn (Request $r): Response => $this->invoke($route['handler'], $r);
            $chain = array_reduce(
                array_reverse($names),
                fn (callable $next, string $name) => fn (Request $r): Response => self::resolve($name)($r, $next),
                $core,
            );
            return $chain($request);
        }
        throw new HttpException($pathMatched ? 405 : 404);
    }

    private static function resolve(string $name): callable
    {
        if (isset(self::$middleware[$name])) {
            return self::$middleware[$name];
        }
        if ($name === 'csrf') {
            return static function (Request $r, callable $next): Response {
                Csrf::check($r);
                return $next($r);
            };
        }
        throw new \LogicException("Middleware '$name' nao registrado");
    }

    private function invoke(array|callable $handler, Request $r): Response
    {
        if (is_array($handler) && is_string($handler[0])) {
            $handler = [new $handler[0](), $handler[1]];
        }
        return $handler($r);
    }
}
