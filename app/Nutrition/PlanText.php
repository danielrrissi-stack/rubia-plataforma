<?php
declare(strict_types=1);

namespace App\Nutrition;

/** Converte o texto colado do plano alimentar em HTML seguro (sem links, sem HTML da entrada). */
final class PlanText
{
    public static function normalizePaste(string $raw): string
    {
        $t = str_replace(["\r\n", "\r"], "\n", $raw);
        $t = str_replace(["\t", "\u{00A0}"], ' ', $t);
        $t = str_replace(["\u{200B}", "\u{200C}", "\u{200D}", "\u{FEFF}"], '', $t);
        $t = (string) preg_replace('/^[ ]*(?:\x{F0B7}|[▪◦‣●])[ ]*/mu', '• ', $t);
        $t = (string) preg_replace('/[ ]+$/mu', '', $t);
        $t = (string) preg_replace('/\n{3,}/', "\n\n", $t);
        return trim($t);
    }

    public static function toHtml(string $text): string
    {
        $out = '';
        $para = [];
        $list = null;   // 'ul' | 'ol'
        $items = [];

        $flushPara = function () use (&$out, &$para): void {
            if ($para) {
                $out .= '<p>' . implode('<br>', $para) . '</p>';
                $para = [];
            }
        };
        $flushList = function () use (&$out, &$list, &$items): void {
            if ($list !== null) {
                $out .= "<$list>" . implode('', $items) . "</$list>";
                $list = null;
                $items = [];
            }
        };

        $lines = array_map('trim', explode("\n", str_replace(["\r\n", "\r"], "\n", $text)));
        $itemRe = '/^([-*•·–]|\d{1,2}[.)])\s+(.+)$/u';
        // Bloco (sem linha vazia) com itens marcados: linhas soltas também viram itens.
        $blockHasItems = [];
        $start = 0;
        foreach ($lines as $i => $l) {
            if ($l === '') {
                $start = $i + 1;
                continue;
            }
            $blockHasItems[$start] ??= false;
            if (preg_match($itemRe, $l)) {
                $blockHasItems[$start] = true;
            }
        }
        $blockStart = 0;
        foreach ($lines as $i => $line) {
            if ($line === '') {
                $flushPara();
                $flushList();
                $blockStart = $i + 1;
                continue;
            }
            if (!preg_match($itemRe, $line) && !self::isTitle($line) && ($blockHasItems[$blockStart] ?? false)) {
                $line = '• ' . $line;
            }
            if (preg_match($itemRe, $line, $m)) {
                $flushPara();
                $kind = ctype_digit($m[1][0]) ? 'ol' : 'ul';
                if ($list !== $kind) {
                    $flushList();
                    $list = $kind;
                }
                $items[] = '<li>' . self::inline($m[2]) . '</li>';
                continue;
            }
            if (self::isTitle($line)) {
                $flushPara();
                $flushList();
                $out .= '<h3>' . self::inline(rtrim(preg_replace('/:$/u', '', $line) ?? $line)) . '</h3>';
                continue;
            }
            $flushList();
            $para[] = self::inline($line);
        }
        $flushPara();
        $flushList();
        return $out;
    }

    private static function isTitle(string $line): bool
    {
        if (str_ends_with($line, ':') && mb_strlen($line) <= 80) {
            return true;
        }
        $letters = preg_match_all('/\p{L}/u', $line);
        return $letters >= 3 && mb_strtoupper($line) === $line && mb_strtolower($line) !== $line;
    }

    private static function inline(string $s): string
    {
        $s = htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return (string) preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $s);
    }
}
