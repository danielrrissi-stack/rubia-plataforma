<?php
declare(strict_types=1);

namespace App\WeightGoals;

use App\Core\Container;
use App\Growth\AdultAnthropometry;
use App\Growth\Age;

/**
 * Converte medidas vigentes em pontos da meta:
 * value = kg (meta 'peso') ou escore-z do IMC (meta 'imc_z'); um ponto por dia (a medida de maior id).
 */
final class WeightGoalPoints
{
    /**
     * @param list<array> $measurements linhas de measurements (MeasurementRepository::current)
     * @return list<array{on:string,value:float,bmi:?float,z:?float,weight:float,height:?float,source:string,id:int}>
     */
    public static function build(string $kind, array $patient, array $measurements, ?string $fromYmd): array
    {
        $birth = (string) $patient['birth_date'];
        $sex = (string) $patient['sex'];
        $byDay = [];
        foreach ($measurements as $m) {
            if ($m['superseded_at'] !== null || $m['deleted_at'] !== null || $m['weight_kg'] === null) {
                continue;
            }
            $on = substr((string) $m['measured_on'], 0, 10);
            if ($fromYmd !== null && $on < $fromYmd) {
                continue;
            }
            $w = (float) $m['weight_kg'];
            $h = $m['height_cm'] === null ? null : (float) $m['height_cm'];
            $bmi = null;
            $z = null;
            if ($h !== null) {
                if (Age::months($birth, $on) >= WeightGoalKind::ADULT_MONTHS) {
                    $bmi = AdultAnthropometry::bmi($w, $h);
                } else {
                    $bfa = Container::growth()->indicators($sex, $birth, $on, $w, $h, $m['position'], null)['bfa'];
                    $bmi = $bfa === null ? null : (float) $bfa['value'];
                    $z = $bfa === null ? null : (float) $bfa['z'];
                }
            }
            $value = $kind === WeightGoalKind::IMC_Z ? $z : $w;
            if ($value === null) {
                continue;
            }
            $point = ['on' => $on, 'value' => $value, 'bmi' => $bmi, 'z' => $z, 'weight' => $w, 'height' => $h, 'source' => (string) $m['source'], 'id' => (int) $m['id']];
            if (!isset($byDay[$on]) || $point['id'] > $byDay[$on]['id']) {
                $byDay[$on] = $point;
            }
        }
        ksort($byDay);
        return array_values($byDay);
    }

    /** Ponto de partida de uma meta nova: o mais recente que serve para o tipo. */
    public static function starting(string $kind, array $patient, array $measurements): ?array
    {
        $all = self::build($kind, $patient, $measurements, null);
        return $all === [] ? null : $all[count($all) - 1];
    }
}
