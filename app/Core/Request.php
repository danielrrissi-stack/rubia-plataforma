<?php
declare(strict_types=1);

namespace App\Core;

final class Request
{
    /** @var array<string,string> parametros de rota preenchidos pelo Router */
    public array $params = [];
    /** Dados que middlewares podem anexar (ex.: usuario autenticado). */
    public array $attributes = [];

    public function __construct(
        private string $method,
        private string $path,
        public array $query = [],
        public array $post = [],
        public array $files = [],
        public array $server = [],
    ) {
        $this->method = strtoupper($method);
    }

    public static function fromGlobals(): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = rawurldecode((string) parse_url($uri, PHP_URL_PATH));
        return new self(
            (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $path === '' ? '/' : $path,
            $_GET,
            $_POST,
            $_FILES,
            $_SERVER,
        );
    }

    public function input(string $k, mixed $d = null): mixed
    {
        return $this->post[$k] ?? $this->query[$k] ?? $d;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function userAgent(): string
    {
        return substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public function isHttps(): bool
    {
        $https = $this->server['HTTPS'] ?? '';
        return ($https !== '' && $https !== 'off')
            || ($this->server['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }
}
