<?php
declare(strict_types=1);

namespace App\WeightGoals;

use App\Growth\Age;

/**
 * Situacao da meta de peso (calculada, nunca gravada).
 * Caminho combinado = reta de (start_on, start_value) a (target_date, target_value); depois do prazo, o alvo.
 * Tendencia = minimos quadrados sobre os pontos, so a partir do 5o ponto (contando o de partida).
 */
final class WeightGoalProgress
{
    public const MIN_TREND_POINTS = 5;
    public const TOLERANCE = [WeightGoalKind::PESO => 1.0, WeightGoalKind::IMC_Z => 0.2];
    public const LABELS = [
        'no_caminho' => 'No caminho',
        'a_frente' => 'À frente do caminho',
        'atras' => 'Atrás do caminho',
        'atingida' => 'Meta atingida',
        'prazo_vencido' => 'Prazo vencido',
        'aguardando' => 'Aguardando a primeira medição',
    ];

    /**
     * @param list<array{on:string,value:float}> $points ordenados por data, a partir de start_on
     * @return array{status:string,last:?array,expected:?float,diff:?float,reachedOn:?string,trend:?array{a:float,b:float,kind:string,date:?string,onTime:?bool},trendMissing:int}
     */
    public static function compute(array $goal, array $points, string $today): array
    {
        $start = (float) $goal['start_value'];
        $target = (float) $goal['target_value'];
        $startOn = substr((string) $goal['start_on'], 0, 10);
        $deadline = substr((string) $goal['target_date'], 0, 10);
        $dir = $target > $start ? 1 : -1;
        $tol = self::TOLERANCE[(string) $goal['kind']] ?? 1.0;

        $reachedOn = null;
        foreach ($points as $pt) {
            if (self::reached($pt['value'], $target, $dir)) {
                $reachedOn = $pt['on'];
                break;
            }
        }
        $last = $points === [] ? null : $points[count($points) - 1];
        $expected = $last === null ? null : self::expected($goal, $last['on']);
        $diff = $last === null ? null : round($last['value'] - $expected, 3);

        if ($last !== null && self::reached($last['value'], $target, $dir)) {
            $status = 'atingida';
        } elseif ($today > $deadline) {
            $status = 'prazo_vencido';
        } elseif ($last === null || $last['on'] <= $startOn) {
            $status = 'aguardando';
        } elseif (abs($diff) <= $tol) {
            $status = 'no_caminho';
        } elseif ($dir * $diff > 0) {
            $status = 'a_frente';
        } else {
            $status = 'atras';
        }

        $n = count($points);
        return [
            'status' => $status,
            'last' => $last,
            'expected' => $expected,
            'diff' => $diff,
            'reachedOn' => $reachedOn,
            'trend' => $n >= self::MIN_TREND_POINTS ? self::trend($points, $startOn, $target, $dir, $deadline, $today) : null,
            'trendMissing' => max(0, self::MIN_TREND_POINTS - $n),
        ];
    }

    /** Valor esperado no caminho combinado na data. */
    public static function expected(array $goal, string $onYmd): float
    {
        $start = (float) $goal['start_value'];
        $target = (float) $goal['target_value'];
        $total = Age::days((string) $goal['start_on'], (string) $goal['target_date']);
        $x = Age::days((string) $goal['start_on'], $onYmd);
        if ($total <= 0 || $x >= $total) {
            return $target;
        }
        return $start + ($target - $start) * max(0, $x) / $total;
    }

    public static function trendText(array $progress): ?string
    {
        if ($progress['status'] === 'atingida') {
            return null;
        }
        $t = $progress['trend'];
        if ($t === null) {
            return 'A tendência aparece a partir da 5ª medição (faltam ' . $progress['trendMissing'] . ').';
        }
        return match ($t['kind']) {
            'nao_aproxima' => 'Nesse ritmo, não se aproxima do alvo.',
            'mais_de_5_anos' => 'Nesse ritmo, levaria mais de 5 anos.',
            'ja_deveria' => 'Pela média das medições já estaria no alvo, mas a última medição ainda não chegou lá.',
            default => 'Nesse ritmo, chega ao alvo em ' . substr($t['date'], 5, 2) . '/' . substr($t['date'], 0, 4)
                . ($t['onTime'] ? ', antes do prazo.' : ', depois do prazo.'),
        };
    }

    private static function reached(float $v, float $target, int $dir): bool
    {
        return $dir * round($v - $target, 3) >= 0;
    }

    /** @param list<array{on:string,value:float}> $points */
    private static function trend(array $points, string $startOn, float $target, int $dir, string $deadline, string $today): array
    {
        $xs = array_map(static fn (array $p): int => Age::days($startOn, $p['on']), $points);
        $ys = array_map(static fn (array $p): float => (float) $p['value'], $points);
        $n = count($xs);
        $mx = array_sum($xs) / $n;
        $my = array_sum($ys) / $n;
        $sxx = 0.0;
        $sxy = 0.0;
        foreach ($xs as $i => $x) {
            $sxx += ($x - $mx) ** 2;
            $sxy += ($x - $mx) * ($ys[$i] - $my);
        }
        $b = $sxx > 0 ? $sxy / $sxx : 0.0;
        $a = $my - $b * $mx;
        if ($dir * $b <= 1e-12) {
            return ['a' => $a, 'b' => $b, 'kind' => 'nao_aproxima', 'date' => null, 'onTime' => null];
        }
        $cross = (int) round(($target - $a) / $b);
        $limit = Age::days($startOn, (new \DateTimeImmutable($today))->modify('+5 years')->format('Y-m-d'));
        if ($cross > $limit) {
            return ['a' => $a, 'b' => $b, 'kind' => 'mais_de_5_anos', 'date' => null, 'onTime' => null];
        }
        $todayX = Age::days($startOn, $today);
        if ($cross <= $todayX) {
            // a reta cruza o alvo antes de hoje (atingiu e voltou, por exemplo): nao mostrar data passada
            return ['a' => $a, 'b' => $b, 'kind' => 'ja_deveria', 'date' => null, 'onTime' => null];
        }
        $date = (new \DateTimeImmutable($startOn, new \DateTimeZone('UTC')))->modify(($cross >= 0 ? '+' : '') . $cross . ' days')->format('Y-m-d');
        return ['a' => $a, 'b' => $b, 'kind' => 'chega', 'date' => $date, 'onTime' => $date <= $deadline];
    }
}
