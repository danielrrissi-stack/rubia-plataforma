<?php
declare(strict_types=1);

namespace App\Nutrition;

use DateTimeImmutable;

/** Regras de data de um plano alimentar por dias (v2). A v1 conta como um único dia sem data. Sem banco. */
final class PlanDays
{
    public static function isMulti(?array $structure): bool
    {
        return $structure !== null && (int) ($structure['v'] ?? 1) === 2;
    }

    /** @return list<array{date:?string, meals:array}> */
    public static function days(array $structure): array
    {
        if (!self::isMulti($structure)) {
            return [['date' => null, 'meals' => $structure['meals'] ?? []]];
        }
        $out = [];
        foreach (array_values($structure['days'] ?? []) as $i => $day) {
            $out[] = ['date' => self::addDays((string) $structure['start'], $i), 'meals' => $day['meals'] ?? []];
        }
        return $out;
    }

    public static function lastDate(array $structure): ?string
    {
        if (!self::isMulti($structure)) {
            return null;
        }
        $n = count($structure['days'] ?? []);
        return $n > 0 ? self::addDays((string) $structure['start'], $n - 1) : null;
    }

    public static function indexFor(array $structure, string $ymd): ?int
    {
        if (!self::isMulti($structure)) {
            return 0;
        }
        $n = count($structure['days'] ?? []);
        if ($n === 0) {
            return null;
        }
        $offset = self::diff((string) $structure['start'], $ymd);
        if ($offset < 0) {
            return null;
        }
        if ($offset < $n) {
            return $offset;
        }
        $k = min(7, $n);
        return $n - $k + (($offset - $n) % $k);
    }

    /** @return list<array{date:string, index:int}> */
    public static function window(array $structure, string $today, int $size = 7): array
    {
        if (!self::isMulti($structure)) {
            return [];
        }
        $first = max($today, (string) $structure['start']);
        $out = [];
        for ($i = 0; $i < $size; $i++) {
            $date = self::addDays($first, $i);
            $idx = self::indexFor($structure, $date);
            if ($idx !== null) {
                $out[] = ['date' => $date, 'index' => $idx];
            }
        }
        return $out;
    }

    public static function dayStructure(array $structure, int $index): array
    {
        if (!self::isMulti($structure)) {
            return $structure;
        }
        return ['v' => 1, 'meals' => $structure['days'][$index]['meals'] ?? []];
    }

    public static function remainingFrom(array $structure, string $today): int
    {
        $last = self::lastDate($structure);
        if ($last === null) {
            return 0;
        }
        return max(0, self::diff($today, $last) + 1);
    }

    /** Rotulo curto de um dia datado: "Dia 24/11 (ter): ". */
    public static function shortLabel(string $ymd): string
    {
        $d = new DateTimeImmutable($ymd);
        $names = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'];
        return 'Dia ' . $d->format('d/m') . ' (' . $names[(int) $d->format('w')] . '): ';
    }

    private static function addDays(string $ymd, int $n): string
    {
        return (new DateTimeImmutable($ymd))->modify(($n >= 0 ? '+' : '') . $n . ' days')->format('Y-m-d');
    }

    private static function diff(string $from, string $to): int
    {
        $d = (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to));
        return $d->invert ? -$d->days : $d->days;
    }
}
