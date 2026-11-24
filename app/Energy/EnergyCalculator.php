<?php
declare(strict_types=1);

namespace App\Energy;

/** Taxa metabolica basal (kcal/dia) por Mifflin-St Jeor, Harris-Benedict e FAO/OMS 1985 (Schofield). */
final class EnergyCalculator
{
    public const FORMULAS = [
        'mifflin' => 'Mifflin-St Jeor',
        'harris_benedict' => 'Harris-Benedict',
        'fao_oms_1985' => 'FAO/OMS 1985 (Schofield)',
    ];

    public static function mifflin(string $sex, float $kg, float $cm, float $ageYears): float
    {
        return 10 * $kg + 6.25 * $cm - 5 * $ageYears + ($sex === 'M' ? 5 : -161);
    }

    public static function harrisBenedict(string $sex, float $kg, float $cm, float $ageYears): float
    {
        if ($sex === 'M') {
            return 66.473 + 13.7516 * $kg + 5.0033 * $cm - 6.755 * $ageYears;
        }
        return 655.0955 + 9.5634 * $kg + 1.8496 * $cm - 4.6756 * $ageYears;
    }

    public static function faoOms1985(string $sex, float $kg, float $ageYears): float
    {
        // [limite inferior (inclusivo), coeficiente, constante], da maior faixa para a menor
        $bands = $sex === 'M'
            ? [[60, 13.5, 487], [30, 11.6, 879], [18, 15.3, 679], [10, 17.5, 651], [3, 22.7, 495], [0, 60.9, -54]]
            : [[60, 10.5, 596], [30, 8.7, 829], [18, 14.7, 496], [10, 12.2, 746], [3, 22.5, 499], [0, 61.0, -51]];
        foreach ($bands as [$from, $a, $b]) {
            if ($ageYears >= $from) {
                return $a * $kg + $b;
            }
        }
        return $bands[5][1] * $kg + $bands[5][2];
    }

    /** @return array{mifflin:?int,harris_benedict:?int,fao_oms_1985:int} */
    public static function all(string $sex, float $kg, float $cm, float $ageYears): array
    {
        $adult = $ageYears >= 18;
        return [
            'mifflin' => $adult ? (int) round(self::mifflin($sex, $kg, $cm, $ageYears)) : null,
            'harris_benedict' => $adult ? (int) round(self::harrisBenedict($sex, $kg, $cm, $ageYears)) : null,
            'fao_oms_1985' => (int) round(self::faoOms1985($sex, $kg, $ageYears)),
        ];
    }
}
