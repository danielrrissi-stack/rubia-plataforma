<?php
declare(strict_types=1);

namespace App\Labs;

final class LabValue
{
    /** @return array{num: ?float, text: ?string}|null null = valor não informado */
    public static function parse(string $raw): ?array
    {
        $s = trim($raw);
        if ($s === '') {
            return null;
        }
        if (preg_match('/^\d+([.,]\d+)?$/', $s) === 1) {
            return ['num' => (float) str_replace(',', '.', $s), 'text' => null];
        }
        if (mb_strlen($s) <= 40
            && (preg_match('/^[<>≤≥]/u', $s) === 1 || preg_match('/^[\p{L} ]+$/u', $s) === 1)) {
            return ['num' => null, 'text' => $s];
        }
        throw new \InvalidArgumentException('Valor de exame inválido: ' . $s);
    }
}
