<?php
declare(strict_types=1);

namespace App\Support;

final class Dates
{
    /** Aceita dd/mm/aaaa ou aaaa-mm-dd; devolve Y-m-d. */
    public static function parseBr(string $raw): ?string
    {
        $s = trim($raw);
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $s, $m)) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('#^(\d{4})-(\d{2})-(\d{2})$#', $s, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            throw new \InvalidArgumentException('Data inválida.');
        }
        if (!checkdate($mo, $d, $y)) {
            throw new \InvalidArgumentException('Data inválida.');
        }
        return sprintf('%04d-%02d-%02d', $y, $mo, $d);
    }
}
