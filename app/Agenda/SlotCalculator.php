<?php
declare(strict_types=1);

namespace App\Agenda;

/**
 * Grade de horarios de um dia (pura): semana-modelo + extras − fechamentos.
 * Horarios aceitos como 'H:i' ou 'H:i:s'; devolvidos como 'H:i'. Intervalos meio-abertos [inicio, fim).
 */
final class SlotCalculator
{
    /**
     * @param list<array{weekday:int,start_time:string,end_time:string}> $ranges semana-modelo (todas as faixas; filtra pelo dia)
     * @param list<array> $exceptions linhas de schedule_exceptions (kind, start_date, end_date, start_time, end_time)
     * @return list<array{start:string,end:string,source:string}>
     */
    public static function daySlots(string $ymd, array $ranges, array $exceptions, int $minutes): array
    {
        if ($minutes <= 0) {
            return [];
        }
        $weekday = AgendaStatus::weekday($ymd);
        $slots = [];
        foreach ($ranges as $r) {
            if ((int) $r['weekday'] === $weekday) {
                self::fill($slots, (string) $r['start_time'], (string) $r['end_time'], $minutes, 'modelo');
            }
        }
        foreach ($exceptions as $x) {
            if ((string) $x['kind'] === 'extra' && self::covers($x, $ymd) && $x['start_time'] !== null && $x['end_time'] !== null) {
                self::fill($slots, (string) $x['start_time'], (string) $x['end_time'], $minutes, 'extra');
            }
        }
        ksort($slots);
        $out = [];
        foreach ($slots as $start => $slot) {
            if (!self::closedOn($ymd, $slot['start'], $slot['end'], $exceptions)) {
                $out[] = $slot;
            }
        }
        return $out;
    }

    public static function overlaps(string $aStart, string $aEnd, string $bStart, string $bEnd): bool
    {
        return self::min($aStart) < self::min($bEnd) && self::min($bStart) < self::min($aEnd);
    }

    /** Algum fechamento que cobre $ymd se sobrepoe a [start, end)? Fechamento sem horario = dia inteiro. */
    public static function closedOn(string $ymd, string $start, string $end, array $exceptions): bool
    {
        foreach ($exceptions as $x) {
            if ((string) $x['kind'] !== 'fechado' || !self::covers($x, $ymd)) {
                continue;
            }
            if ($x['start_time'] === null || $x['end_time'] === null) {
                return true;
            }
            if (self::overlaps($start, $end, (string) $x['start_time'], (string) $x['end_time'])) {
                return true;
            }
        }
        return false;
    }

    /** @param array<int,array{start:string,end:string,source:string}> $slots indexado por minuto de inicio (o primeiro vence) */
    private static function fill(array &$slots, string $from, string $to, int $minutes, string $source): void
    {
        $end = self::min($to);
        for ($t = self::min($from); $t + $minutes <= $end; $t += $minutes) {
            $slots[$t] ??= ['start' => self::fmt($t), 'end' => self::fmt($t + $minutes), 'source' => $source];
        }
    }

    private static function covers(array $exception, string $ymd): bool
    {
        return ($exception['deleted_at'] ?? null) === null
            && (string) $exception['start_date'] <= $ymd && $ymd <= (string) $exception['end_date'];
    }

    private static function min(string $hm): int
    {
        $p = explode(':', $hm);
        return (int) $p[0] * 60 + (int) ($p[1] ?? 0);
    }

    private static function fmt(int $m): string
    {
        return sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
    }
}
