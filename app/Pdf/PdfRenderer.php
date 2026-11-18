<?php
declare(strict_types=1);

namespace App\Pdf;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Gera PDF no servidor com o Dompdf versionado em lib/dompdf.
 * Nada externo: recursos remotos, PHP e JavaScript embutidos ficam desligados.
 */
final class PdfRenderer
{
    public function __construct(private string $libDir, private string $cacheDir)
    {
    }

    /** Documento HTML completo com o CSS do PDF embutido; $bodyHtml ja vem escapado pela view. */
    public function document(string $title, string $bodyHtml): string
    {
        $css = (string) file_get_contents(__DIR__ . '/pdf.css');
        return '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8">'
            . '<title>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</title>'
            . '<style>' . $css . '</style></head><body>' . $bodyHtml . '</body></html>';
    }

    /** Bytes do PDF (A4 retrato). */
    public function render(string $title, string $bodyHtml): string
    {
        require_once $this->libDir . '/autoload.inc.php';
        $cache = $this->usableCacheDir();
        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setChroot([$this->libDir]);
        $options->setFontDir($cache);
        $options->setFontCache($cache);
        $options->setTempDir($cache);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->document($title, $bodyHtml), 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        return (string) $dompdf->output();
    }

    private function usableCacheDir(): string
    {
        $dir = $this->cacheDir;
        if (!is_dir($dir) && !is_file($dir)) {
            @mkdir($dir, 0700, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            return $dir;
        }
        $fallback = rtrim(sys_get_temp_dir(), '/\\') . '/rubia-dompdf';
        if (!is_dir($fallback)) {
            @mkdir($fallback, 0700, true);
        }
        return $fallback;
    }
}
