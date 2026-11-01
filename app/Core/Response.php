<?php
declare(strict_types=1);

namespace App\Core;

final class Response
{
    /** Caminho de arquivo a transmitir em send() (respostas file()). */
    public ?string $filePath = null;

    /** @param array<string,string> $headers */
    public function __construct(public string $body = '', public int $status = 200, public array $headers = [])
    {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function redirect(string $to, int $status = 302): self
    {
        return new self('', $status, ['Location' => $to]);
    }

    public static function json(array $data, int $status = 200): self
    {
        return new self(
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $status,
            ['Content-Type' => 'application/json; charset=utf-8'],
        );
    }

    public static function file(string $path, string $mime, ?string $downloadName = null): self
    {
        $r = new self('', 200, [
            'Content-Type' => $mime,
            'Content-Length' => (string) (is_file($path) ? filesize($path) : 0),
            'X-Content-Type-Options' => 'nosniff',
        ]);
        if ($downloadName !== null) {
            $safe = str_replace(['"', "\r", "\n", '\\'], '', $downloadName);
            $r->headers['Content-Disposition'] = 'attachment; filename="' . $safe . '"';
        }
        $r->filePath = $path;
        return $r;
    }

    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        if ($this->filePath !== null) {
            readfile($this->filePath);
            return;
        }
        echo $this->body;
    }
}
