<?php
declare(strict_types=1);

namespace App\Support;

final class Num
{
    /** Converte entrada do usuario ("32,5", "1.234,5") em float; vazio => null. */
    public static function parse(?string $raw): ?float
    {
        $s = trim((string) $raw);
        if ($s === '') {
            return null;
        }
        $commas = substr_count($s, ',');
        $dots = substr_count($s, '.');
        if ($commas > 1) {
            throw new \InvalidArgumentException('Número inválido.');
        }
        if ($commas === 1) {
            // virgula = decimal; pontos so valem como separador de milhar
            if (!preg_match('/^-?(\d{1,3}(\.\d{3})*|\d+),\d+$/', $s)) {
                throw new \InvalidArgumentException('Número inválido.');
            }
            $s = str_replace(',', '.', str_replace('.', '', $s));
        } elseif ($dots > 1) {
            if (!preg_match('/^-?\d{1,3}(\.\d{3})+$/', $s)) {
                throw new \InvalidArgumentException('Número inválido.');
            }
            $s = str_replace('.', '', $s);
        } elseif (!preg_match('/^-?\d+(\.\d+)?$/', $s)) {
            throw new \InvalidArgumentException('Número inválido.');
        }
        return (float) $s;
    }
}
