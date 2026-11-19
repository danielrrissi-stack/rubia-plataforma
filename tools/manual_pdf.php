<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('403 Forbidden');
}

/**
 * Gera os seis manuais em PDF em public/assets/manual/pdf/ (feito na maquina de desenvolvimento, nunca no servidor).
 * Usa as copias reduzidas das fotos que tools/manual_shots.mjs grava em storage/demo/pdf-img/ (sem elas, as fotos
 * em tamanho cheio — o PDF fica maior). Uso: php tools/manual_pdf.php
 */
require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Container;
use App\Core\View;
use App\Help\Manual;

function manualPdfHtml(string $role, string $todayYmd): string
{
    $sections = Manual::sections($role, true);
    $images = [];
    foreach ($sections as $s) {
        foreach ($s['images'] as $img) {
            $small = APP_ROOT . '/storage/demo/pdf-img/' . $img['file'];
            $full = APP_ROOT . '/public/assets/manual/' . $img['file'];
            $src = is_file($small) ? $small : $full;
            if (is_file($src)) {
                $images[$img['file']] = 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($src));
            }
        }
    }
    $date = (new DateTimeImmutable($todayYmd))->format('d/m/Y');
    return View::render('Help/views/pdf.php', ['role' => $role, 'sections' => $sections, 'date' => $date, 'images' => $images], null);
}

if (isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $dir = APP_ROOT . '/public/assets/manual/pdf';
    @mkdir($dir, 0775, true);
    foreach (Manual::ROLES as $role) {
        $bytes = Container::pdf()->render(Manual::title($role), manualPdfHtml($role, date('Y-m-d')));
        $file = APP_ROOT . '/public' . Manual::pdfPath($role);
        file_put_contents($file, $bytes);
        printf("%s  %.0f KB\n", basename($file), strlen($bytes) / 1024);
    }
}
