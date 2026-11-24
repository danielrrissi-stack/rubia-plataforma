<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Pdf\PdfRenderer;
use PHPUnit\Framework\TestCase;

final class PdfRendererTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rubia-pdf-test-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmp)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->tmp);
        } elseif (is_file($this->tmp)) {
            unlink($this->tmp);
        }
    }

    private function renderer(string $cacheDir): PdfRenderer
    {
        return new PdfRenderer(APP_ROOT . '/lib/dompdf', $cacheDir);
    }

    public function test_render_returns_pdf_bytes(): void
    {
        $pdf = $this->renderer($this->tmp . '/cache')->render('Teste', '<p>Olá — ação</p>');
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('%%EOF', $pdf);
    }

    public function test_document_embeds_css_and_escapes_title(): void
    {
        $html = $this->renderer($this->tmp . '/cache')->document('A & <B>', '<p>x</p>');
        $this->assertStringContainsString('<style>', $html);
        $this->assertStringContainsString('A &amp; &lt;B&gt;', $html);
        $this->assertStringContainsString('<p>x</p>', $html);
        $this->assertStringContainsString('<meta charset="utf-8">', $html);
    }

    public function test_creates_missing_cache_dir(): void
    {
        $dir = $this->tmp . '/a/b';
        $this->renderer($dir)->render('T', '<p>x</p>');
        $this->assertDirectoryExists($dir);
    }

    public function test_falls_back_to_temp_when_cache_dir_unusable(): void
    {
        file_put_contents($this->tmp, 'not a dir');
        $pdf = $this->renderer($this->tmp)->render('T', '<p>x</p>');
        $this->assertStringStartsWith('%PDF-', $pdf);
    }

    public function test_never_executes_php_from_the_writable_cache_dir(): void
    {
        $dir = $this->tmp . '/cache';
        mkdir($dir, 0700, true);
        file_put_contents($dir . '/dompdf_font_family_cache.php', '<?php $GLOBALS["rubia_planted"] = true; return [];');
        unset($GLOBALS['rubia_planted']);
        $pdf = $this->renderer($dir)->render('T', '<p>x</p>');
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertArrayNotHasKey('rubia_planted', $GLOBALS);
    }

    public function test_remote_resources_are_not_fetched(): void
    {
        $pdf = $this->renderer($this->tmp . '/cache')->render('T', '<p>x</p><img src="http://127.0.0.1:9/x.png">');
        $this->assertStringStartsWith('%PDF-', $pdf);
    }

    public function test_optional_css_and_font_dir(): void
    {
        $r = $this->renderer($this->tmp . '/cache');
        $css = APP_ROOT . '/app/Letterhead/papel.css';
        $this->assertStringNotContainsString('Nunito', $r->render('T', '<p>x</p>'));
        $this->assertStringContainsString('Nunito', $r->render('T', '<p style="font-family: nunito">x</p>', $css, APP_ROOT . '/lib/papel-fonts'));
        $this->assertStringContainsString('pt-titulo', $r->document('T', '', $css));
        $this->assertStringNotContainsString('pt-titulo', $r->document('T', ''));
    }
}
