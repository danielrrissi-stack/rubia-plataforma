<?php
declare(strict_types=1);

namespace App\Growth;

/** Velocidade de crescimento em altura (cm/ano). */
final class Velocity
{
    public const MIN_INTERVAL_DAYS = 90;

    /**
     * Pares consecutivos (por data) de medidas com altura; intervalos < 90 dias sao ignorados.
     *
     * @param array<int,array{measured_on:string,height_cm:mixed}> $measurements
     * @return list<array{from:string,to:string,cm_per_year:float}>
     */
    public static function heightVelocity(array $measurements): array
    {
        $withHeight = array_values(array_filter(
            $measurements,
            static fn(array $m): bool => isset($m['height_cm']) && $m['height_cm'] !== '',
        ));
        usort($withHeight, static fn(array $a, array $b): int => strcmp((string) $a['measured_on'], (string) $b['measured_on']));
        $out = [];
        for ($i = 1; $i < count($withHeight); $i++) {
            $a = $withHeight[$i - 1];
            $b = $withHeight[$i];
            $days = Age::days((string) $a['measured_on'], (string) $b['measured_on']);
            if ($days < self::MIN_INTERVAL_DAYS) {
                continue;
            }
            $delta = (float) $b['height_cm'] - (float) $a['height_cm'];
            $out[] = [
                'from' => substr((string) $a['measured_on'], 0, 10),
                'to' => substr((string) $b['measured_on'], 0, 10),
                'cm_per_year' => round($delta / $days * 365.25, 1) + 0.0,
            ];
        }
        return $out;
    }
}
