<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Help\Manual;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/tools/manual_pdf.php';

final class HelpPdfTest extends TestCase
{
    public function test_pdf_html_has_cover_notice_and_embedded_images(): void
    {
        $html = manualPdfHtml('responsavel', '2026-11-19');
        $this->assertStringContainsString(e('Manual do responsável'), $html);
        $this->assertStringContainsString(e(Manual::NOTICE), $html);
        $this->assertStringContainsString('19/11/2026', $html);
        $this->assertStringContainsString('data:image/jpeg;base64,', $html);
        $this->assertStringNotContainsString('src="/assets', $html);
        $this->assertStringContainsString(e(Manual::CAPTION_SUFFIX), $html);
    }

    public function test_doctor_pdf_includes_admin_sections_marked(): void
    {
        $html = manualPdfHtml('medico', '2026-11-19');
        $this->assertStringContainsString('(só para administradora)', $html);
    }

    public function test_every_pdf_exists(): void
    {
        foreach (Manual::ROLES as $role) {
            $f = dirname(__DIR__, 2) . '/public' . Manual::pdfPath($role);
            $this->assertFileExists($f, 'rode php tools/manual_pdf.php');
            $this->assertSame('%PDF', (string) file_get_contents($f, false, null, 0, 4), $f);
        }
    }
}
