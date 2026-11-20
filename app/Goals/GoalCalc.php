<?php
declare(strict_types=1);

namespace App\Goals;

use DateTimeImmutable;

final class GoalCalc
{
    public const BADGE_LABELS = [
        'primeira_estrela' => 'Primeira estrela',
        'estrelas_10' => '10 estrelas',
        'estrelas_50' => '50 estrelas',
        'estrelas_100' => '100 estrelas',
        'estrelas_250' => '250 estrelas',
        'semana_completa' => 'Semana completa',
        'sequencia_3' => '3 semanas seguidas',
        'sequencia_8' => '8 semanas seguidas',
    ];

    private const STAR_BADGES = [
        'primeira_estrela' => 1,
        'estrelas_10' => 10,
        'estrelas_50' => 50,
        'estrelas_100' => 100,
        'estrelas_250' => 250,
    ];

    public static function weekStart(string $ymd): string
    {
        $d = new DateTimeImmutable($ymd);
        return $d->modify('-' . ((int) $d->format('N') - 1) . ' days')->format('Y-m-d');
    }

    private static function inPeriod(array $goal, string $day): bool
    {
        return $day >= $goal['start_date'] && ($goal['end_date'] === null || $day <= $goal['end_date']);
    }

    public static function canMark(array $goal, string $day, string $today): bool
    {
        $min = (new DateTimeImmutable($today))->modify('-6 days')->format('Y-m-d');
        return $day >= $min && $day <= $today && self::inPeriod($goal, $day);
    }

    public static function activeDaysInWeek(array $goal, string $weekStart): int
    {
        $n = 0;
        $d = new DateTimeImmutable($weekStart);
        for ($i = 0; $i < 7; $i++, $d = $d->modify('+1 day')) {
            if (self::inPeriod($goal, $d->format('Y-m-d'))) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * @param list<string> $checkDays
     * @return array{done:int, target:int, applicable:bool, complete:bool}
     */
    public static function weekStatus(array $goal, array $checkDays, string $weekStart): array
    {
        $target = min((int) $goal['times_per_week'], self::activeDaysInWeek($goal, $weekStart));
        $weekEnd = (new DateTimeImmutable($weekStart))->modify('+6 days')->format('Y-m-d');
        $done = count(array_unique(array_filter(
            $checkDays,
            fn (string $d): bool => $d >= $weekStart && $d <= $weekEnd && self::inPeriod($goal, $d)
        )));
        $applicable = $target > 0;
        return ['done' => $done, 'target' => $target, 'applicable' => $applicable, 'complete' => $applicable && $done >= $target];
    }

    public static function stars(array $goals, array $checks): int
    {
        $byId = [];
        foreach ($goals as $g) {
            $byId[$g['id']] = $g;
        }
        // Uma estrela por (meta, dia): duas pessoas marcando o mesmo dia nao contam em dobro.
        $seen = [];
        foreach ($checks as $c) {
            if (isset($byId[$c['goal_id']]) && self::inPeriod($byId[$c['goal_id']], $c['day'])) {
                $seen[$c['goal_id'] . '|' . $c['day']] = true;
            }
        }
        return count($seen);
    }

    /** @return list<string> */
    public static function badges(array $goals, array $checks, string $today): array
    {
        $stars = self::stars($goals, $checks);
        $badges = [];
        foreach (self::STAR_BADGES as $key => $min) {
            if ($stars >= $min) {
                $badges[] = $key;
            }
        }

        $days = [];
        foreach ($checks as $c) {
            $days[$c['goal_id']][] = $c['day'];
        }

        $best = 0;
        $run = 0;
        if ($goals !== []) {
            $w = new DateTimeImmutable(self::weekStart(min(array_column($goals, 'start_date'))));
            while ($w->modify('+6 days')->format('Y-m-d') < $today) {
                $ws = $w->format('Y-m-d');
                $applicable = 0;
                $allDone = true;
                foreach ($goals as $g) {
                    $s = self::weekStatus($g, $days[$g['id']] ?? [], $ws);
                    if ($s['applicable']) {
                        $applicable++;
                        $allDone = $allDone && $s['complete'];
                    }
                }
                $run = ($applicable > 0 && $allDone) ? $run + 1 : 0;
                $best = max($best, $run);
                $w = $w->modify('+7 days');
            }
        }
        if ($best >= 1) {
            $badges[] = 'semana_completa';
        }
        if ($best >= 3) {
            $badges[] = 'sequencia_3';
        }
        if ($best >= 8) {
            $badges[] = 'sequencia_8';
        }
        return $badges;
    }
}
