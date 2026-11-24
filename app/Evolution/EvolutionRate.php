<?php
declare(strict_types=1);

namespace App\Evolution;

use App\Growth\Age;

/**
 * Calculos puros do painel de evolucao: trechos de dose, ritmo por trecho e aviso de "estabilizou".
 * Ritmo sempre pela reta de minimos quadrados (uma pesagem fora da curva nao decide sozinha).
 * Sinal: taxa positiva = perdeu (kg ou escore-z); negativa = ganhou.
 */
final class EvolutionRate
{
    public const WINDOW_DAYS = 28;
    public const MIN_POINTS = 3;
    public const MIN_SPAN_DAYS = 14;
    public const SEGMENT_MIN_DAYS = 7;

    /** @param list<array{on:string,value:float}> $pts @return ?array{a:float,b:float,x0:string} */
    public static function slopePerDay(array $pts): ?array
    {
        if (count($pts) < 2) {
            return null;
        }
        $x0 = $pts[0]['on'];
        $n = count($pts);
        $sx = $sy = $sxx = $sxy = 0.0;
        foreach ($pts as $p) {
            $x = (float) Age::days($x0, $p['on']);
            $y = (float) $p['value'];
            $sx += $x;
            $sy += $y;
            $sxx += $x * $x;
            $sxy += $x * $y;
        }
        $den = $n * $sxx - $sx * $sx;
        if ($den == 0.0) {
            return null;
        }
        $b = ($n * $sxy - $sx * $sy) / $den;
        return ['a' => ($sy - $b * $sx) / $n, 'b' => $b, 'x0' => $x0];
    }

    /**
     * Trechos de dose de uma prescricao: de max(effective_date, start_date) ate a vespera da proxima dose,
     * ou end_date, ou null (vigente). Doses que comecam depois de hoje nao entram.
     * @param list<array> $doses ordenadas por effective_date
     * @return list<array{from:string,to:?string,dose:string}>
     */
    public static function segments(array $prescription, array $doses, string $today): array
    {
        $start = (string) $prescription['start_date'];
        $end = $prescription['end_date'] === null ? null : (string) $prescription['end_date'];
        $doses = array_values(array_filter($doses, static fn (array $d): bool => (string) $d['effective_date'] <= $today));
        $out = [];
        foreach ($doses as $i => $d) {
            $from = max((string) $d['effective_date'], $start);
            $to = isset($doses[$i + 1]) ? self::dayBefore((string) $doses[$i + 1]['effective_date']) : $end;
            if ($end !== null && $to !== null && $to > $end) {
                $to = $end;
            }
            if ($to !== null && $to < $from) {
                continue;
            }
            $out[] = ['from' => $from, 'to' => $to, 'dose' => (string) $d['dose']];
        }
        return $out;
    }

    /** kg/semana perdidos (adulto) ou queda de escore-z em 4 semanas (crianca) no trecho; null com poucos dados. */
    public static function segmentRate(array $pts, array $seg, string $today, bool $child): ?float
    {
        $to = $seg['to'] ?? $today;
        $in = array_values(array_filter($pts, static fn (array $p): bool => $p['on'] >= $seg['from'] && $p['on'] <= $to));
        if (count($in) < 2 || Age::days($in[0]['on'], $in[count($in) - 1]['on']) < self::SEGMENT_MIN_DAYS) {
            return null;
        }
        $fit = self::slopePerDay($in);
        if ($fit === null) {
            return null;
        }
        return $child ? -$fit['b'] * 28 : -$fit['b'] * 7;
    }

    /**
     * Aviso sobre as ultimas 4 semanas ate a ultima pesagem (comeca na mudanca de dose mais recente, se cair dentro).
     * @param list<string> $changeDates datas de inicio de remedio e de mudanca de dose
     * @param array{adult_mode:string,kg_per_week:float,pct_per_4w:float,z_per_4w:float} $settings
     * @return array{status:string,rate:?float,unit:string,daysSinceChange:?int,from:?string,to:?string}
     */
    public static function plateau(array $pts, array $changeDates, array $settings, bool $child): array
    {
        $unit = $child ? 'z_4sem' : ($settings['adult_mode'] === 'pct' ? 'pct_4sem' : 'kg_semana');
        $out = ['status' => 'sem_dados', 'rate' => null, 'unit' => $unit, 'daysSinceChange' => null, 'from' => null, 'to' => null];
        if ($pts === []) {
            return $out;
        }
        $last = $pts[count($pts) - 1]['on'];
        $start = (new \DateTimeImmutable($last))->modify('-' . self::WINDOW_DAYS . ' days')->format('Y-m-d');
        $change = null;
        foreach ($changeDates as $c) {
            if ($c > $start && $c <= $last && ($change === null || $c > $change)) {
                $change = $c;
            }
        }
        if ($change !== null) {
            $start = $change;
            $out['daysSinceChange'] = Age::days($change, $last);
        }
        $out['from'] = $start;
        $out['to'] = $last;
        $in = array_values(array_filter($pts, static fn (array $p): bool => $p['on'] >= $start && $p['on'] <= $last));
        $fit = count($in) >= self::MIN_POINTS && Age::days($in[0]['on'], $last) >= self::MIN_SPAN_DAYS ? self::slopePerDay($in) : null;
        if ($fit === null) {
            $out['status'] = 'aguardando';
            return $out;
        }
        if ($unit === 'kg_semana') {
            $rate = -$fit['b'] * 7;
            $limit = (float) $settings['kg_per_week'];
        } elseif ($unit === 'pct_4sem') {
            $rate = $fit['a'] > 0 ? -$fit['b'] * 28 / $fit['a'] * 100 : 0.0;
            $limit = (float) $settings['pct_per_4w'];
        } else {
            $rate = -$fit['b'] * 28;
            $limit = (float) $settings['z_per_4w'];
        }
        $out['rate'] = $rate;
        $out['status'] = $rate < $limit ? 'estabilizou' : 'funcionando';
        return $out;
    }

    public static function plateauText(array $p): string
    {
        if ($p['status'] === 'sem_dados') {
            return 'Sem pesagens para avaliar.';
        }
        if ($p['status'] === 'aguardando') {
            return $p['daysSinceChange'] !== null
                ? 'Aguardando dados: nova dose há ' . $p['daysSinceChange'] . ' ' . ($p['daysSinceChange'] === 1 ? 'dia' : 'dias') . '.'
                : 'Aguardando dados: são precisas 3 pesagens em pelo menos 14 dias.';
        }
        $rate = self::rateText((float) $p['rate'], (string) $p['unit']);
        return $p['status'] === 'estabilizou'
            ? 'Perda de peso estabilizou nesta dose (' . $rate . ').'
            : 'Ainda perdendo peso: ' . $rate . '.';
    }

    /** "0,35 kg/semana nas últimas 4 semanas", "0,84% do peso em 4 semanas", "queda de 0,084 no escore-z em 4 semanas". */
    public static function rateText(float $rate, string $unit): string
    {
        return match ($unit) {
            'pct_4sem' => self::num($rate, 2) . '% do peso em 4 semanas',
            'z_4sem' => 'queda de ' . self::num($rate, 3) . ' no escore-z em 4 semanas',
            default => self::num($rate, 2) . ' kg/semana nas últimas 4 semanas',
        };
    }

    private static function num(float $v, int $dec): string
    {
        $s = number_format($v, $dec, ',', '.');
        return $s === '-' . number_format(0, $dec, ',', '.') ? number_format(0, $dec, ',', '.') : $s;
    }

    private static function dayBefore(string $ymd): string
    {
        return (new \DateTimeImmutable($ymd))->modify('-1 day')->format('Y-m-d');
    }
}
