<?php
declare(strict_types=1);

namespace App\Timeline;

use App\Core\Container;
use App\Growth\Age;

/**
 * Periodo da linha do tempo. Padrao "desde a ultima visita": a ultima consulta realizada ANTES de hoje
 * (a consulta de hoje e a que esta acontecendo); senao a ultima evolucao antes de hoje; senao tudo.
 */
final class TimelinePeriod
{
    public const CHOICES = ['visita' => 'Desde a última visita', '3m' => '3 meses', '12m' => '12 meses', 'tudo' => 'Tudo'];

    /** @return array{choice:string, from:?string, ref:?string, label:string, days:?int} */
    public static function resolve(int $patientId, string $choice, string $today): array
    {
        $choice = isset(self::CHOICES[$choice]) ? $choice : 'visita';
        $d = new \DateTimeImmutable($today, new \DateTimeZone('UTC'));
        switch ($choice) {
            case '3m':
                return ['choice' => $choice, 'from' => $d->modify('-3 months')->format('Y-m-d'), 'ref' => null, 'label' => 'Últimos 3 meses', 'days' => null];
            case '12m':
                return ['choice' => $choice, 'from' => $d->modify('-12 months')->format('Y-m-d'), 'ref' => null, 'label' => 'Últimos 12 meses', 'days' => null];
            case 'tudo':
                return ['choice' => $choice, 'from' => null, 'ref' => null, 'label' => 'Tudo', 'days' => null];
        }
        $db = Container::db();
        $visit = $db->one(
            "SELECT MAX(starts_at) AS at FROM appointments WHERE patient_id = ? AND status = 'realizada' AND starts_at < ?",
            [$patientId, $today . ' 00:00:00'],
        )['at'] ?? null;
        $ref = $visit !== null ? substr((string) $visit, 0, 10) : null;
        if ($ref === null) {
            $evo = $db->one(
                "SELECT MAX(encounter_date) AS d FROM clinical_entries
                 WHERE patient_id = ? AND kind = 'evolucao' AND superseded_at IS NULL AND deleted_at IS NULL AND encounter_date < ?",
                [$patientId, $today],
            )['d'] ?? null;
            $ref = $evo !== null ? substr((string) $evo, 0, 10) : null;
        }
        if ($ref === null) {
            return ['choice' => $choice, 'from' => null, 'ref' => null, 'label' => 'Sem visita anterior — mostrando tudo', 'days' => null];
        }
        return ['choice' => $choice, 'from' => $ref, 'ref' => $ref, 'label' => 'Desde a última visita', 'days' => Age::days($ref, $today)];
    }
}
