<?php
declare(strict_types=1);

namespace App\Evolution;

use App\Core\Container;
use App\Growth\Age;
use App\WeightGoals\WeightGoalKind;

/**
 * Pontos da curva do painel: kg (adulto) ou escore-z do IMC (menos de 229 meses).
 * Pesagem sem altura usa a ultima altura medida nos 90 dias anteriores; sem ela, fica fora da curva de escore-z.
 */
final class EvolutionPoints
{
    public const HEIGHT_DAYS = 90;

    public static function isChild(array $patient, string $today): bool
    {
        return Age::months((string) $patient['birth_date'], $today) < WeightGoalKind::ADULT_MONTHS;
    }

    /**
     * @param list<array> $measurements linhas de MeasurementRepository::current (ordem de data e id)
     * @return list<array{on:string,value:float,weight:float,source:string,id:int}>
     */
    public static function build(array $patient, array $measurements, bool $child): array
    {
        $birth = (string) $patient['birth_date'];
        $sex = (string) ($patient['sex'] ?? 'F');
        usort($measurements, static fn (array $a, array $b): int
            => [substr((string) $a['measured_on'], 0, 10), (int) $a['id']] <=> [substr((string) $b['measured_on'], 0, 10), (int) $b['id']]);
        $lastHeight = null; // [on, cm, position]
        $byDay = [];
        foreach ($measurements as $m) {
            if ($m['superseded_at'] !== null || $m['deleted_at'] !== null) {
                continue;
            }
            $on = substr((string) $m['measured_on'], 0, 10);
            if ($m['height_cm'] !== null) {
                $lastHeight = [$on, (float) $m['height_cm'], $m['position']];
            }
            if ($m['weight_kg'] === null) {
                continue;
            }
            $w = (float) $m['weight_kg'];
            $value = $w;
            if ($child) {
                if ($lastHeight === null || Age::days($lastHeight[0], $on) > self::HEIGHT_DAYS) {
                    continue;
                }
                $bfa = Container::growth()->indicators($sex, $birth, $on, $w, $lastHeight[1], $lastHeight[2], null)['bfa'];
                if ($bfa === null || $bfa['z'] === null) {
                    continue;
                }
                $value = (float) $bfa['z'];
            }
            $byDay[$on] = ['on' => $on, 'value' => $value, 'weight' => $w, 'source' => (string) $m['source'], 'id' => (int) $m['id']];
        }
        ksort($byDay);
        return array_values($byDay);
    }
}
