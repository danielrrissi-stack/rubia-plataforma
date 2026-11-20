<?php
declare(strict_types=1);

namespace App\Pdf;

/** Nome do arquivo baixado: "<prefixo> - <nome> - dd-mm-aaaa.pdf". */
final class PdfFilename
{
    public static function make(string $prefix, string $name, string $dateYmd): string
    {
        $clean = preg_replace('~[\\\\/:*?"<>|\p{Cc}]+~u', ' ', $name) ?? '';
        $clean = trim(preg_replace('/\s+/u', ' ', $clean) ?? '');
        $clean = mb_substr($clean, 0, 80);
        [$y, $m, $d] = explode('-', $dateYmd) + ['', '', ''];
        return $prefix . ' - ' . trim($clean) . ' - ' . $d . '-' . $m . '-' . $y . '.pdf';
    }
}
