<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Response;
use App\Pdf\PdfFilename;
use PHPUnit\Framework\TestCase;

final class PdfFilenameTest extends TestCase
{
    public function test_builds_name_with_brazilian_date(): void
    {
        $this->assertSame('Receita - Maria Silva - 18-11-2026.pdf', PdfFilename::make('Receita', 'Maria Silva', '2026-11-18'));
    }

    public function test_strips_invalid_and_control_characters(): void
    {
        $this->assertSame('Receita - Ana Bia Souza - 05-01-2026.pdf', PdfFilename::make('Receita', "Ana / \"Bia\"\r\n: Souza", '2026-01-05'));
    }

    public function test_keeps_accents(): void
    {
        $this->assertSame('Plano alimentar - João Ávila - 18-11-2026.pdf', PdfFilename::make('Plano alimentar', 'João Ávila', '2026-11-18'));
    }

    public function test_truncates_long_names_to_80_chars(): void
    {
        $name = PdfFilename::make('Receita', str_repeat('é', 200), '2026-11-18');
        $this->assertSame('Receita - ' . str_repeat('é', 80) . ' - 18-11-2026.pdf', $name);
    }

    public function test_response_pdf_headers(): void
    {
        $r = Response::pdf('%PDF-1', 'Receita - José - 18-11-2026.pdf');
        $this->assertSame('application/pdf', $r->headers['Content-Type']);
        $this->assertStringStartsWith('attachment; ', $r->headers['Content-Disposition']);
        $this->assertStringContainsString('filename="Receita - Jos_ - 18-11-2026.pdf"', $r->headers['Content-Disposition']);
        $this->assertStringContainsString("filename*=UTF-8''Receita%20-%20Jos%C3%A9%20-%2018-11-2026.pdf", $r->headers['Content-Disposition']);
        $this->assertSame('no-store', $r->headers['Cache-Control']);
        $this->assertSame('nosniff', $r->headers['X-Content-Type-Options']);
        $this->assertSame('%PDF-1', $r->body);
        $this->assertSame(200, $r->status);
    }
}
