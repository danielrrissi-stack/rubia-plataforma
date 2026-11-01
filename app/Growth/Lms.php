<?php
declare(strict_types=1);

namespace App\Growth;

/** Metodo LMS (Cole) usado pelas curvas OMS: escore Z, inverso e percentil. */
final class Lms
{
    public static function z(float $x, float $l, float $m, float $s): float
    {
        if ($l == 0.0) {
            return log($x / $m) / $s;
        }
        return (($x / $m) ** $l - 1) / ($l * $s);
    }

    /**
     * Metodo restrito da OMS (indicadores de peso): alem de |z| = 3 a distancia
     * passa a ser medida em unidades fixas de (SD3 - SD2), sem a assimetria do LMS.
     */
    public static function zRestricted(float $x, float $l, float $m, float $s): float
    {
        $z = self::z($x, $l, $m, $s);
        if ($z > 3) {
            $sd3 = self::valueAt(3, $l, $m, $s);
            $sd23 = $sd3 - self::valueAt(2, $l, $m, $s);
            return 3 + ($x - $sd3) / $sd23;
        }
        if ($z < -3) {
            $sd3n = self::valueAt(-3, $l, $m, $s);
            $sd23n = self::valueAt(-2, $l, $m, $s) - $sd3n;
            return -3 + ($x - $sd3n) / $sd23n;
        }
        return $z;
    }

    /** Medida correspondente ao escore Z (para desenhar curvas). */
    public static function valueAt(float $z, float $l, float $m, float $s): float
    {
        if ($l == 0.0) {
            return $m * exp($s * $z);
        }
        return $m * (1 + $l * $s * $z) ** (1 / $l);
    }

    /** Percentil = Phi(z) * 100, com 1 casa decimal. */
    public static function percentile(float $z): float
    {
        $p = 0.5 * (1 + self::erf($z / M_SQRT2)) * 100;
        return round($p, 1) + 0.0;
    }

    /** erf por Abramowitz-Stegun 7.1.26 (erro absoluto < 1,5e-7). */
    private static function erf(float $x): float
    {
        $sign = $x < 0 ? -1.0 : 1.0;
        $x = abs($x);
        $t = 1 / (1 + 0.3275911 * $x);
        $poly = $t * (0.254829592 + $t * (-0.284496736 + $t * (1.421413741 + $t * (-1.453152027 + $t * 1.061405429))));
        return $sign * (1 - $poly * exp(-$x * $x));
    }
}
