<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * A CSP (default-src 'self', sem 'unsafe-inline') bloqueia atributos style="..." e handlers on*="...".
 * Nenhuma view pode usa-los: estilos vao para public/assets/css/app.css e comportamento para JS externo.
 */
final class ViewsCspTest extends TestCase
{
    /** @return string[] */
    private static function views(): array
    {
        $root = dirname(__DIR__, 2) . '/app';
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $path = str_replace('\\', '/', $f->getPathname());
            if ($f->isFile() && preg_match('#/views/[^/]+\.php$#', $path)) {
                $out[] = $path;
            }
        }
        return $out;
    }

    public function testViewsExist(): void
    {
        $this->assertGreaterThan(20, count(self::views()));
    }

    public function testNoInlineStyleOrEventHandlerAttributes(): void
    {
        $bad = [];
        foreach (self::views() as $path) {
            foreach (file($path) as $n => $line) {
                if (preg_match('/\sstyle\s*=\s*["\']|\son[a-z]+\s*=\s*["\']/i', $line)) {
                    $bad[] = basename(dirname($path, 2)) . '/views/' . basename($path) . ':' . ($n + 1);
                }
            }
        }
        $this->assertSame([], $bad);
    }

    public function testNoInlineScriptBlocks(): void
    {
        $bad = [];
        foreach (self::views() as $path) {
            if (preg_match('/<script(?![^>]*\bsrc=)[^>]*>/i', (string) file_get_contents($path))) {
                $bad[] = $path;
            }
        }
        $this->assertSame([], $bad);
    }
}
