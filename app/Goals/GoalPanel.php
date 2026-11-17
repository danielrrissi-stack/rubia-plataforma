<?php
declare(strict_types=1);

namespace App\Goals;

use DateTimeImmutable;

/** Monta a grade semanal "feito/meta" por meta para as telas da equipe (so apresentacao; regras ficam no GoalCalc). */
final class GoalPanel
{
    public const CATEGORY_LABELS = [
        'alimentacao' => 'Alimentação', 'hidratacao' => 'Hidratação', 'sono' => 'Sono',
        'atividade' => 'Atividade', 'telas' => 'Telas', 'rotina' => 'Rotina',
    ];

    /**
     * @param list<array> $goals
     * @param list<array{goal_id:int, day:string}> $checks
     * @return array{weeks: list<string>, rows: list<array{goal: array, cells: list<array{done:int,target:int,applicable:bool,complete:bool}>}>}
     *         weeks = segundas-feiras das $completed semanas terminadas + a semana atual (ultima).
     */
    public static function build(array $goals, array $checks, string $today, int $completed): array
    {
        $current = GoalCalc::weekStart($today);
        $weeks = [];
        for ($i = $completed; $i >= 0; $i--) {
            $weeks[] = (new DateTimeImmutable($current))->modify('-' . ($i * 7) . ' days')->format('Y-m-d');
        }
        $days = [];
        foreach ($checks as $c) {
            $days[$c['goal_id']][] = $c['day'];
        }
        $rows = [];
        foreach ($goals as $g) {
            $cells = [];
            foreach ($weeks as $ws) {
                $cells[] = GoalCalc::weekStatus($g, $days[(int) $g['id']] ?? [], $ws);
            }
            $rows[] = ['goal' => $g, 'cells' => $cells];
        }
        return ['weeks' => $weeks, 'rows' => $rows];
    }

    /** Meta em curso: sem fim ou com fim depois de hoje (encerrar = fim hoje, ja aparece em Encerradas). */
    public static function isActive(array $goal, string $today): bool
    {
        return $goal['end_date'] === null || $goal['end_date'] > $today;
    }
}
