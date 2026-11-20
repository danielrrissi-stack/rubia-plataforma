<?php
declare(strict_types=1);

namespace App\Growth;

/** Formatacao de indicadores para exibicao (virgula decimal, pt-BR). */
final class GrowthFormat
{
    /** >= 99,95 vira ">99,9"; <= 0,05 vira "<0,1"; demais com 1 casa. */
    public static function percentile(float $p): string
    {
        if ($p >= 99.95) {
            return '>99,9';
        }
        if ($p <= 0.05) {
            return '<0,1';
        }
        return fmt_num($p, 1);
    }

    /** Z com 2 casas e sinal: "+1,25", "-0,50", "0,00". */
    public static function z(float $z): string
    {
        $z = round($z, 2);
        if ($z == 0.0) {
            return '0,00';
        }
        return ($z > 0 ? '+' : '-') . fmt_num(abs($z), 2);
    }

    /**
     * @param ?array $ind resultado de GrowthCalculator::indicators para um indicador
     * @return ?array{z:string,p:string,status:string,implausible:bool}
     */
    public static function indicator(?array $ind): ?array
    {
        if ($ind === null) {
            return null;
        }
        return [
            'z' => self::z((float) $ind['z']),
            'p' => self::percentile((float) $ind['percentile']),
            'status' => (string) $ind['status'],
            'implausible' => (bool) $ind['implausible'],
        ];
    }
}
