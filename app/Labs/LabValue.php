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

    /** Casas para exibir: as do catalogo, sem nunca mostrar menos casas do que o valor gravado precisa (ate 4). */
    public static function displayDecimals(float $v, int $catalog): int
    {
        $dec = max(0, $catalog);
        while ($dec < 4 && round($v, $dec) != $v) {
            $dec++;
        }
        return $dec;
    }

    /** Valor numerico formatado com virgula, sem arredondar para menos casas do que o necessario. */
    public static function format(float $v, int $catalog): string
    {
        return fmt_num($v, self::displayDecimals($v, $catalog));
    }
}
