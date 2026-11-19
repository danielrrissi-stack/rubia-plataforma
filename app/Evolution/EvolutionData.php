<?php
declare(strict_types=1);

namespace App\Evolution;

use App\Core\Container;
use App\Growth\Age;
use App\Growth\BloodPressure;
use App\Timeline\TimelinePeriod;
use App\WeightGoals\WeightGoalKind;

/** Monta os dados do painel (pagina e JSON). O aviso usa todas as pesagens; o resto e cortado ao periodo. */
final class EvolutionData
{
    public const PERIODS = ['3m' => 'Últimos 3 meses', '6m' => 'Últimos 6 meses', '12m' => 'Últimos 12 meses', 'tudo' => 'Tudo'];
    public const DEFAULT_PERIOD = '6m';
    public const LAB_CODES = ['glicemia', 'hba1c', 'insulina', 'triglicerides', 'hdl', 'tgp'];

    public static function period(string $choice): string
    {
        return isset(self::PERIODS[$choice]) ? $choice : self::DEFAULT_PERIOD;
    }

    public static function from(string $period, string $today): ?string
    {
        $months = ['3m' => 3, '6m' => 6, '12m' => 12][$period] ?? null;
        return $months === null ? null : TimelinePeriod::monthsBack(new \DateTimeImmutable($today, new \DateTimeZone('UTC')), $months);
    }

    public static function build(array $user, array $patient, string $today, string $period): array
    {
        $pid = (int) $patient['id'];
        $from = self::from($period, $today);
        $inPeriod = static fn (string $ymd): bool => $from === null || $ymd >= $from;
        $child = EvolutionPoints::isChild($patient, $today);
        $measurements = Container::measurements()->current($pid);
        $all = EvolutionPoints::build($patient, $measurements, $child);
        $settings = Container::evolutionSettings()->get($pid);

        $meds = [];
        $changes = [];
        foreach (Container::prescriptions()->forViewer($pid, $user, $today) as $rx) {
            $doses = Container::prescriptions()->doses((int) $rx['id']);
            $end = $rx['end_date'] === null ? null : (string) $rx['end_date'];
            foreach (array_merge([(string) $rx['start_date']], array_map(static fn (array $d): string => (string) $d['effective_date'], $doses)) as $c) {
                if ($end === null || $c <= $end) {
                    $changes[] = $c;
                }
            }
            $segments = [];
            foreach (EvolutionRate::segments($rx, $doses, $today) as $seg) {
                if ($from !== null && $seg['to'] !== null && $seg['to'] < $from) {
                    continue;
                }
                $segments[] = $seg + ['rate' => EvolutionRate::segmentRate($all, $seg, $today, $child)];
            }
            if ($segments !== []) {
                $meds[] = ['name' => (string) $rx['drug_name'], 'segments' => $segments];
            }
        }

        $plateau = EvolutionRate::plateau($all, $changes, $settings, $child);
        $goal = Container::weightGoals()->active($pid);
        $path = null;
        if ($goal !== null && ($goal['kind'] === WeightGoalKind::IMC_Z) === $child) {
            $path = [[(string) $goal['start_on'], (float) $goal['start_value']], [(string) $goal['target_date'], (float) $goal['target_value']]];
        }

        return [
            'child' => $child,
            'unit' => $child ? 'z' : 'kg',
            'from' => $from,
            'to' => $today,
            'points' => array_values(array_map(
                static fn (array $p): array => [$p['on'], round($p['value'], 3), $p['source']],
                array_filter($all, static fn (array $p): bool => $inPeriod($p['on'])),
            )),
            'path' => $path,
            'meds' => $meds,
            'plateau' => ['status' => $plateau['status'], 'text' => EvolutionRate::plateauText($plateau)] + $plateau,
            'settings' => $settings,
            'metabolism' => self::metabolism($patient, $measurements, $inPeriod),
        ];
    }

    private static function metabolism(array $patient, array $measurements, callable $inPeriod): array
    {
        $birth = (string) $patient['birth_date'];
        $sex = (string) $patient['sex'];
        $waist = [];
        $sys = [];
        $dia = [];
        foreach ($measurements as $m) {
            $on = substr((string) $m['measured_on'], 0, 10);
            if (!$inPeriod($on)) {
                continue;
            }
            if ($m['waist_cm'] !== null) {
                $waist[] = [$on, (float) $m['waist_cm'], null];
            }
            if ($m['systolic'] !== null && $m['diastolic'] !== null) {
                $sys[] = [$on, (int) $m['systolic'], self::bpClass(self::bpLabel($sex, $birth, $on, $m))];
                $dia[] = [$on, (int) $m['diastolic']];
            }
        }
        $out = [];
        if ($waist !== []) {
            $out[] = ['key' => 'cintura', 'label' => 'Cintura', 'unit' => 'cm', 'points' => $waist, 'points2' => null];
        }
        if ($sys !== []) {
            $out[] = ['key' => 'pa', 'label' => 'Pressão arterial', 'unit' => 'mmHg', 'points' => $sys, 'points2' => $dia];
        }
        foreach (self::LAB_CODES as $code) {
            $test = Container::labCatalog()->findByCode($code);
            if ($test === null) {
                continue;
            }
            $pts = [];
            foreach (Container::labs()->series((int) $patient['id'], (int) $test['id'])['points'] as $p) {
                if ($inPeriod($p['date'])) {
                    $pts[] = [$p['date'], $p['value'], $p['class']];
                }
            }
            if ($pts !== []) {
                $out[] = ['key' => $code, 'label' => (string) $test['name'], 'unit' => (string) $test['unit'], 'points' => $pts, 'points2' => null];
            }
        }
        return $out;
    }

    private static function bpLabel(string $sex, string $birth, string $on, array $m): string
    {
        if (Age::months($birth, $on) >= WeightGoalKind::ADULT_MONTHS) {
            return BloodPressure::classifyAdult((int) $m['systolic'], (int) $m['diastolic']);
        }
        $ind = Container::growth()->indicators($sex, $birth, $on, null, $m['height_cm'] === null ? null : (float) $m['height_cm'], $m['position'], null);
        return BloodPressure::classifyChild($sex, Age::years($birth, $on), $ind['hfa']['percentile'] ?? null, (int) $m['systolic'], (int) $m['diastolic'], Container::bpRef());
    }

    /** normal | limitrofe | alterado | null (sem classificacao) */
    public static function bpClass(string $label): ?string
    {
        if (in_array($label, ['Normal', 'PA ótima', 'PA normal'], true)) {
            return 'normal';
        }
        if (in_array($label, ['PA elevada', 'Pré-hipertensão'], true)) {
            return 'limitrofe';
        }
        return str_starts_with($label, 'HA ') ? 'alterado' : null;
    }
}
