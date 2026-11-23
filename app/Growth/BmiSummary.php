<?php
declare(strict_types=1);

namespace App\Growth;

/**
 * Resumo do IMC (ultima medida com peso e altura): categoria, tom e peso minimo e maximo para a altura.
 * Menores de 19 anos: Eutrofia da OMS (escore Z do IMC de -2 a +1) na idade da medida. Adultos: IMC 18,5 a 24,9.
 */
final class BmiSummary
{
    private const ADULT_MIN = 18.5;
    private const ADULT_MAX = 24.9;

    /**
     * @param list<array<string,mixed>> $measures medidas vigentes, da mais antiga para a mais nova
     * @return ?array{date:string,age:string,adult:bool,bmi:float,status:string,tone:string,weight:float,height:float,min:float,max:float,implausible:bool,abran:?array{bmiMin:float,bmiMax:float,min:float,max:float}}
     */
    public static function make(array $patient, array $measures, GrowthCalculator $calc): ?array
    {
        $m = null;
        foreach ($measures as $row) {
            if ($row['weight_kg'] !== null && $row['height_cm'] !== null) {
                $m = $row;
            }
        }
        if ($m === null) {
            return null;
        }
        $sex = (string) $patient['sex'];
        $birth = (string) $patient['birth_date'];
        $on = substr((string) $m['measured_on'], 0, 10);
        $kg = (float) $m['weight_kg'];
        $cm = (float) $m['height_cm'];
        $out = ['date' => $on, 'age' => Age::label($birth, $on), 'weight' => $kg, 'height' => $cm];

        if (Age::months($birth, $on) >= GrowthCalculator::WHO2007_MAX_MONTHS) {
            $bmi = AdultAnthropometry::bmi($kg, $cm);
            $sq = ($cm / 100) ** 2;
            return $out + [
                'adult' => true, 'bmi' => $bmi, 'status' => AdultAnthropometry::bmiClass($bmi), 'tone' => self::adultTone($bmi),
                'min' => self::up(self::ADULT_MIN * $sq), 'max' => self::down(self::ADULT_MAX * $sq), 'implausible' => false, 'abran' => null,
            ];
        }

        $ind = $calc->indicators($sex, $birth, $on, $kg, $cm, $m['position'], null)['bfa'];
        $low = $calc->bmiForZ($sex, $birth, $on, -2.0);
        $high = $calc->bmiForZ($sex, $birth, $on, 1.0);
        if ($ind === null || $low === null || $high === null) {
            return null;
        }
        $abran = AbranBmi::normal($sex, Age::years($birth, $on));
        $abranSq = ($cm / 100) ** 2;
        $sq = ($calc->correctHeight($cm, Age::days($birth, $on), $m['position']) / 100) ** 2;
        return $out + [
            'adult' => false, 'bmi' => round((float) $ind['value'], 1), 'status' => (string) $ind['status'],
            'tone' => GrowthBands::bmiTone((float) $ind['z']),
            'min' => self::up($low * $sq), 'max' => self::down($high * $sq), 'implausible' => (bool) $ind['implausible'],
            'abran' => $abran === null ? null : [
                'bmiMin' => $abran[0], 'bmiMax' => $abran[1],
                'min' => self::up($abran[0] * $abranSq), 'max' => self::down($abran[1] * $abranSq),
            ],
        ];
    }

    private static function adultTone(float $bmi): string
    {
        return match (true) {
            $bmi < 18.5 => 'alerta',
            $bmi < 25.0 => 'ok',
            $bmi < 30.0 => 'atencao',
            $bmi < 35.0 => 'alerta',
            default => 'grave',
        };
    }

    // Arredonda para dentro da faixa: os pesos mostrados sempre classificam como Eutrofia.
    private static function up(float $kg): float
    {
        return ceil(round($kg * 10, 6)) / 10;
    }

    private static function down(float $kg): float
    {
        return floor(round($kg * 10, 6)) / 10;
    }
}
