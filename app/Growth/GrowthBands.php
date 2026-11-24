<?php
declare(strict_types=1);

namespace App\Growth;

/**
 * Graficos por faixa de classificacao (altura, peso e IMC para a idade), com os mesmos cortes de escore Z de
 * NutritionalStatus. Tons: ok (verde), atencao (ambar), alerta (vermelho) e grave (cinza-escuro).
 */
final class GrowthBands
{
    /** Faixas etarias do grafico: chave => [rotulo, primeiro mes, ultimo mes]. */
    public const AGES = [
        '0-2' => ['0 a 2 anos', 0, 24],
        '2-5' => ['2 a 5 anos', 24, 60],
        '5-10' => ['5 a 10 anos', 60, 120],
        '10-19' => ['10 a 19 anos', 120, 228],
    ];
    private const TITLES = ['hfa' => 'Altura (cm)', 'wfa' => 'Peso (kg)', 'bfa' => 'IMC (kg/m²)'];
    private const CUTS = ['hfa' => [-3, -2], 'wfa' => [-3, -2, 2], 'bfa' => [-3, -2, 1, 2, 3]];
    private const ZONES = [
        'hfa' => [['Muito baixo', 'grave'], ['Baixo', 'alerta'], ['Adequado', 'ok']],
        'wfa' => [['Muito baixo', 'grave'], ['Baixo', 'alerta'], ['Adequado', 'ok'], ['Elevado', 'alerta']],
    ];
    private const BMI_TONES = ['grave', 'alerta', 'ok', 'atencao', 'alerta', 'grave'];
    private const BMI_UNDER5 = ['Magreza acentuada', 'Magreza', 'Eutrofia', 'Risco de sobrepeso', 'Sobrepeso', 'Obesidade'];
    private const BMI_SCHOOL = ['Magreza acentuada', 'Magreza', 'Eutrofia', 'Sobrepeso', 'Obesidade', 'Obesidade grave'];

    public function __construct(private WhoReferenceRepository $ref, private GrowthCalculator $calc)
    {
    }

    /** Faixa etaria na data; null a partir de 19 anos (fora das curvas). */
    public static function ageKey(string $birth, string $on): ?string
    {
        $days = Age::days($birth, $on);
        $months = Age::months($birth, $on);
        return match (true) {
            $days < 0, $months >= GrowthCalculator::WHO2007_MAX_MONTHS => null,
            $days <= GrowthCalculator::LENGTH_MAX_DAYS => '0-2',
            $days <= GrowthCalculator::WHO2006_MAX_DAYS => '2-5',
            $months < 120 => '5-10',
            default => '10-19',
        };
    }

    /**
     * Faixas para escolher: a atual e as que tem peso ou altura registrados, em ordem de idade.
     * @param list<array<string,mixed>> $measures
     * @return array<string,string> chave => rotulo
     */
    public static function available(string $birth, string $today, array $measures): array
    {
        $keys = [self::ageKey($birth, $today) => true];
        foreach ($measures as $m) {
            if ($m['weight_kg'] !== null || $m['height_cm'] !== null) {
                $keys[self::ageKey($birth, (string) $m['measured_on'])] = true;
            }
        }
        $out = [];
        foreach (self::AGES as $key => [$label]) {
            if (isset($keys[$key])) {
                $out[$key] = $label;
            }
        }
        return $out;
    }

    /** Tom pelo escore Z do IMC para a idade. */
    public static function bmiTone(float $z): string
    {
        return match (true) {
            $z < -3 => 'grave',
            $z < -2 => 'alerta',
            $z <= 1 => 'ok',
            $z <= 2 => 'atencao',
            $z <= 3 => 'alerta',
            default => 'grave',
        };
    }

    /**
     * @param list<array<string,mixed>> $measures medidas vigentes
     * @return array{faixa:string,x:array{min:int,max:int},charts:list<array<string,mixed>>}
     */
    public function data(array $patient, array $measures, string $key): array
    {
        $sex = (string) $patient['sex'];
        $birth = (string) $patient['birth_date'];
        [, $from, $to] = self::AGES[$key];
        $points = ['hfa' => [], 'wfa' => [], 'bfa' => []];
        foreach ($measures as $m) {
            $on = substr((string) $m['measured_on'], 0, 10);
            if (self::ageKey($birth, $on) !== $key) {
                continue;
            }
            $ind = $this->calc->indicators(
                $sex, $birth, $on,
                $m['weight_kg'] === null ? null : (float) $m['weight_kg'],
                $m['height_cm'] === null ? null : (float) $m['height_cm'],
                $m['position'], null,
            );
            foreach (array_keys($points) as $k) {
                if ($ind[$k] !== null) {
                    $points[$k][] = [
                        'x' => round(Age::months($birth, $on), 2), 'y' => round((float) $ind[$k]['value'], $k === 'bfa' ? 1 : 2),
                        'date' => fmt_date($on), 'bad' => (bool) $ind[$k]['implausible'],
                    ];
                }
            }
        }
        $charts = [];
        foreach (self::TITLES as $k => $title) {
            if ($k === 'wfa' && $to > 120) {
                continue; // a OMS nao tem peso para a idade depois dos 10 anos
            }
            $charts[] = $this->chart($k, $title, $sex, $key, $from, $to, $points[$k]);
        }
        return ['faixa' => $key, 'x' => ['min' => $from, 'max' => $to], 'charts' => $charts];
    }

    private function chart(string $ind, string $title, string $sex, string $key, int $from, int $to, array $points): array
    {
        $lines = array_map(fn (int $z): array => $this->line($ind, $sex, $key, $from, $to, $z), self::CUTS[$ind]);
        $low = array_column($this->line($ind, $sex, $key, $from, $to, -3), 1);
        $high = array_column($this->line($ind, $sex, $key, $from, $to, 3), 1);
        foreach ($points as $p) {
            if (!$p['bad']) {
                $low[] = $p['y'];
                $high[] = $p['y'];
            }
        }
        $step = $ind === 'bfa' ? 2 : 5;
        $span = max($high) - min($low);
        if ($ind === 'bfa') {
            $zones = in_array($key, ['0-2', '2-5'], true) ? self::BMI_UNDER5 : self::BMI_SCHOOL;
            $zones = array_map(static fn (string $l, string $t): array => [$l, $t], $zones, self::BMI_TONES);
        } else {
            $zones = self::ZONES[$ind];
        }
        return [
            'key' => $ind,
            'title' => $title,
            'y' => [
                'min' => max(0, (int) (floor((min($low) - $span * 0.06) / $step) * $step)),
                'max' => (int) (ceil((max($high) + $span * 0.08) / $step) * $step),
            ],
            'zones' => array_map(static fn (array $z): array => ['label' => $z[0], 'tone' => $z[1]], $zones),
            'lines' => $lines,
            'points' => $points,
        ];
    }

    /** @return list<array{0:float,1:float}> [mes, valor] do escore Z ao longo da faixa etaria */
    private function line(string $ind, string $sex, string $key, int $from, int $to, int $z): array
    {
        $out = [];
        for ($m = $from; $m <= $to; $m++) {
            if ($from >= 60) {
                [$source, $x] = ['who2007', (float) $m];
            } else {
                // extremos no lado certo da troca de tabela: 0-730 dias (deitado) e 731-1826 dias (em pe)
                $days = (int) round($m * Age::DAYS_PER_MONTH);
                $days = $key === '0-2' ? min($days, GrowthCalculator::LENGTH_MAX_DAYS) : max($days, GrowthCalculator::LENGTH_MAX_DAYS + 1);
                [$source, $x] = ['who2006', (float) min($days, GrowthCalculator::WHO2006_MAX_DAYS)];
            }
            $lms = $this->ref->lms($ind, $sex, $x, $source);
            if ($lms !== null) {
                $out[] = [(float) $m, round(Lms::valueAt($z, $lms['l'], $lms['m'], $lms['s']), 2)];
            }
        }
        return $out;
    }
}
