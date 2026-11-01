<?php
declare(strict_types=1);

namespace App\Core;

final class View
{
    /**
     * Renderiza app/$template dentro de app/$layout (null = sem layout).
     * O layout recebe $content e todas as variaveis de $data.
     */
    public static function render(string $template, array $data = [], ?string $layout = 'Layout/views/layout.php'): string
    {
        $content = self::capture(dirname(__DIR__) . '/' . $template, $data);
        if ($layout === null) {
            return $content;
        }
        return self::capture(dirname(__DIR__) . '/' . $layout, $data + ['content' => $content]);
    }

    private static function capture(string $file, array $data): string
    {
        if (!is_file($file)) {
            throw new \RuntimeException("Template nao encontrado: $file");
        }
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            include $file;
            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }
}
