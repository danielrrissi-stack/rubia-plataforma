<?php
declare(strict_types=1);

namespace App\Timeline;

use App\Access\Policy;
use App\Agenda\AgendaStatus;
use App\Core\Container;
use App\Growth\AdultAnthropometry;
use App\Growth\Age;
use App\Labs\Clinician;
use App\Nutrition\Staff;
use App\WeightGoals\WeightGoalAccess;
use App\WeightGoals\WeightGoalController;
use App\WeightGoals\WeightGoalKind;

/**
 * Linha do tempo do paciente: so le as tabelas existentes. Cada fonte entra apenas se o usuario
 * pode ver a secao de origem hoje (Gate + regra do modulo). Mais recente primeiro.
 */
final class TimelineBuilder
{
    /** Rotulo de cada tipo, na ordem usada dentro do mesmo dia (consulta por ultimo). */
    public const TYPES = [
        'meta_peso' => 'Meta de peso', 'medicao' => 'Medição', 'exame' => 'Exame', 'remedio' => 'Remédio',
        'evento_adverso' => 'Evento adverso', 'plano' => 'Plano alimentar', 'meta' => 'Meta',
        'evolucao' => 'Evolução', 'atestado' => 'Atestado', 'consulta' => 'Consulta',
    ];

    /** @return array{events: list<array{date:string,type:string,label:string,text:string,link:?string,badge:?string}>, truncated:bool} */
    public static function events(array $user, int $patientId, ?string $from, string $today, int $limit = 200): array
    {
        $gate = Container::gate();
        $patient = Container::patients()->find($patientId);
        if ($patient === null) {
            return ['events' => [], 'truncated' => false];
        }
        $base = '/pacientes/' . $patientId;
        $doctor = Clinician::is($user);
        $ev = [];
        $add = static function (string $date, string $type, string $text, ?string $link, ?string $badge = null) use (&$ev, $from, $today): void {
            $date = substr($date, 0, 10);
            if (($from === null || $date >= $from) && $date <= $today) {
                $ev[] = ['date' => $date, 'type' => $type, 'label' => self::TYPES[$type], 'text' => $text, 'link' => $link === null ? null : url($link), 'badge' => $badge];
            }
        };

        if ($gate->check($user, 'view', Policy::AGENDA, $patientId)) {
            self::visits($user, $patientId, $today, $add);
        }
        if ($doctor && $gate->check($user, 'view', Policy::CLINICO, $patientId)) {
            foreach (Container::clinicalEntries()->evolutions($patientId) as $e) {
                $dx = trim((string) (json_decode((string) $e['payload'], true)['diagnostico'] ?? ''));
                $add((string) ($e['encounter_date'] ?? $e['created_at']), 'evolucao',
                    'Evolução registrada' . ($dx !== '' ? ' — ' . mb_strimwidth($dx, 0, 120, '…') : ''), $base . '/prontuario');
            }
        }
        if ($doctor && $gate->check($user, 'view', Policy::CLINICO, $patientId)) {
            foreach (Container::certificates()->forPatient($patientId) as $c) {
                $add((string) $c['issued_on'], 'atestado', 'Atestado emitido: ' . \App\Certificates\CertificateType::TITLES[$c['type']],
                    $base . '/atestados/' . $c['id'], $c['voided_at'] !== null ? 'anulado' : null);
            }
        }
        if ($doctor && $gate->check($user, 'view', Policy::EXAMES, $patientId)) {
            self::labs($patientId, $base, $add);
        }
        if ($doctor && $gate->check($user, 'view', Policy::MEDICAMENTOS, $patientId)) {
            self::medications($patientId, $base, $today, $add);
        }
        $measurements = Container::measurements()->current($patientId);
        if ($gate->check($user, 'view', Policy::ANTROPOMETRIA, $patientId)) {
            self::measurements($patient, $measurements, $base, $add);
        }
        if (Staff::is($user) && $gate->check($user, 'view', Policy::NUTRICAO, $patientId)) {
            foreach (Container::db()->all(
                "SELECT id, title, published_at FROM meal_plans WHERE patient_id = ? AND published_at IS NOT NULL
                 AND status IN ('publicado','substituido','encerrado')",
                [$patientId],
            ) as $p) {
                $add((string) $p['published_at'], 'plano', 'Plano publicado: ' . $p['title'], '/planos/' . $p['id']);
            }
        }
        if ($doctor && $gate->check($user, 'view', Policy::METAS, $patientId)) {
            foreach (Container::db()->all('SELECT * FROM patient_goals WHERE patient_id = ? AND deleted_at IS NULL', [$patientId]) as $g) {
                $add((string) $g['start_date'], 'meta', 'Nova meta: ' . $g['family_text'] . ' (' . $g['times_per_week'] . 'x/semana)', $base . '/metas');
                if ($g['end_date'] !== null) {
                    $add((string) $g['end_date'], 'meta', 'Meta encerrada: ' . $g['family_text'], $base . '/metas');
                }
            }
        }
        if (WeightGoalAccess::canView($user, $patientId)) {
            self::weightGoals($patient, $measurements, $base, $today, $add);
        }

        $order = array_flip(array_keys(self::TYPES));
        foreach ($ev as $i => &$e) {
            $e['_i'] = $i;
        }
        unset($e);
        usort($ev, static fn (array $a, array $b): int => [$b['date'], $order[$a['type']], $a['_i']] <=> [$a['date'], $order[$b['type']], $b['_i']]);
        $truncated = count($ev) > $limit;
        $ev = array_map(static function (array $e): array {
            unset($e['_i']);
            return $e;
        }, array_slice($ev, 0, $limit));
        return ['events' => $ev, 'truncated' => $truncated];
    }

    private static function visits(array $user, int $patientId, string $today, callable $add): void
    {
        $sql = "SELECT a.id, a.starts_at, a.kind, a.status, a.professional_id, u.name AS professional_name
                FROM appointments a JOIN users u ON u.id = a.professional_id
                WHERE a.patient_id = ? AND a.status IN ('realizada','faltou') AND a.starts_at < ?";
        $params = [$patientId, date('Y-m-d H:i:s')];
        if (($user['role'] ?? '') === 'nutricionista') {
            $sql .= ' AND a.professional_id = ?';
            $params[] = (int) $user['id'];
        }
        $access = Container::agendaAccess();
        $canOpen = [];
        foreach (Container::db()->all($sql, $params) as $a) {
            $prof = (int) $a['professional_id'];
            $canOpen[$prof] ??= $access->canManageExisting($user, $prof);
            // sem link quando a pessoa nao gerencia aquela agenda (a pagina da consulta daria 404)
            $add((string) $a['starts_at'], 'consulta',
                ($a['kind'] === 'primeira' ? 'Primeira consulta' : 'Retorno') . ' com ' . $a['professional_name'] . ' — ' . AgendaStatus::label((string) $a['status']),
                $canOpen[$prof] ? '/agenda/consultas/' . $a['id'] : null);
        }
    }

    private static function labs(int $patientId, string $base, callable $add): void
    {
        $evo = Container::labs()->evolution($patientId, null);
        foreach ($evo['collections'] as $c) {
            $cid = $c['id'];
            $n = 0;
            $altered = [];
            foreach ($evo['rows'] as $row) {
                if (!isset($row['cells'][$cid])) {
                    continue;
                }
                $n++;
                if (str_starts_with((string) ($row['cells'][$cid]['class'] ?? ''), 'alterado_')) {
                    $altered[] = (string) $row['test']['name'];
                }
            }
            $text = 'Coleta: ' . $n . ($n === 1 ? ' resultado' : ' resultados') . ', ' . count($altered) . (count($altered) === 1 ? ' alterado' : ' alterados');
            if ($altered !== []) {
                $text .= ' (' . implode(', ', array_slice($altered, 0, 3)) . (count($altered) > 3 ? ', …' : '') . ')';
            }
            $add($c['collected_on'], 'exame', $text, $base . '/exames');
        }
    }

    private static function medications(int $patientId, string $base, string $today, callable $add): void
    {
        $db = Container::db();
        $byRx = [];
        foreach ($db->all(
            'SELECT d.* FROM prescription_doses d JOIN prescriptions p ON p.id = d.prescription_id
             WHERE p.patient_id = ? AND p.deleted_at IS NULL ORDER BY d.effective_date, d.id',
            [$patientId],
        ) as $d) {
            $byRx[(int) $d['prescription_id']][] = $d;
        }
        foreach ($db->all('SELECT * FROM prescriptions WHERE patient_id = ? AND deleted_at IS NULL', [$patientId]) as $p) {
            $doses = $byRx[(int) $p['id']] ?? [];
            $name = (string) $p['drug_name'];
            $add((string) $p['start_date'], 'remedio', 'Início: ' . $name . ($doses !== [] ? ' ' . $doses[0]['dose'] : ''), $base . '/tratamento');
            for ($i = 1, $n = count($doses); $i < $n; $i++) {
                $add((string) $doses[$i]['effective_date'], 'remedio', $name . ': ' . $doses[$i - 1]['dose'] . ' → ' . $doses[$i]['dose'], $base . '/tratamento');
            }
            if ($p['end_date'] !== null && (string) $p['end_date'] <= $today) {
                $add((string) $p['end_date'], 'remedio', $name . ' encerrado', $base . '/tratamento');
            }
        }
        foreach ($db->all('SELECT * FROM adverse_events WHERE patient_id = ? AND deleted_at IS NULL', [$patientId]) as $a) {
            $sev = ['leve' => 'leve', 'moderada' => 'moderada', 'grave' => 'grave'][$a['severity'] ?? ''] ?? null;
            $add((string) $a['occurred_on'], 'evento_adverso',
                'Evento adverso: ' . mb_strimwidth((string) $a['description'], 0, 120, '…') . ($sev !== null ? ' (' . $sev . ')' : ''),
                $base . '/tratamento', $a['source'] === 'familia' ? 'família' : null);
        }
    }

    private static function measurements(array $patient, array $measurements, string $base, callable $add): void
    {
        $birth = (string) $patient['birth_date'];
        $prevWeight = null;
        foreach ($measurements as $m) {
            $on = substr((string) $m['measured_on'], 0, 10);
            $parts = [];
            $w = $m['weight_kg'] === null ? null : (float) $m['weight_kg'];
            $h = $m['height_cm'] === null ? null : (float) $m['height_cm'];
            if ($w !== null) {
                $delta = $prevWeight === null ? '' : ' (' . self::signed($w - $prevWeight, 1) . ' kg)';
                $parts[] = 'Peso ' . fmt_num($w, 1) . ' kg' . $delta;
                $prevWeight = $w;
            }
            if ($h !== null) {
                $parts[] = 'Altura ' . fmt_num($h, 1) . ' cm';
            }
            if ($w !== null && $h !== null) {
                if (Age::months($birth, $on) >= WeightGoalKind::ADULT_MONTHS) {
                    $parts[] = 'IMC ' . fmt_num(AdultAnthropometry::bmi($w, $h), 1);
                } else {
                    $bfa = Container::growth()->indicators((string) $patient['sex'], $birth, $on, $w, $h, $m['position'], null)['bfa'];
                    if ($bfa !== null) {
                        $parts[] = 'IMC ' . fmt_num((float) $bfa['value'], 1) . ' (escore-z ' . self::signed((float) $bfa['z'], 2) . ')';
                    }
                }
            }
            if ($m['systolic'] !== null && $m['diastolic'] !== null) {
                $parts[] = 'PA ' . $m['systolic'] . '/' . $m['diastolic'];
            }
            if ($parts === []) {
                $parts[] = 'Medidas registradas';
            }
            $add($on, 'medicao', implode(' · ', $parts), $base . '/crescimento', $m['source'] === 'familia' ? 'família' : null);
        }
    }

    private static function weightGoals(array $patient, array $measurements, string $base, string $today, callable $add): void
    {
        $link = $base . '/meta-peso';
        foreach (Container::weightGoals()->history((int) $patient['id']) as $g) {
            $kind = (string) $g['kind'];
            $fmt = static fn (float $v): string => $kind === WeightGoalKind::PESO ? fmt_num($v, 1) . ' kg' : 'escore-z ' . self::signed($v, 2);
            $add((string) $g['created_at'], 'meta_peso', 'Meta definida: alvo ' . $fmt((float) $g['target_value']) . ' até ' . fmt_date((string) $g['target_date']), $link);
            if ($g['ended_at'] !== null) {
                $add((string) $g['ended_at'], 'meta_peso', $g['end_reason'] === 'substituida' ? 'Meta trocada' : 'Meta encerrada', $link);
            }
            $until = $g['ended_at'] === null ? null : substr((string) $g['ended_at'], 0, 10);
            [$points, $progress] = WeightGoalController::evaluate($g, $patient, $today, $until, $measurements);
            if ($progress['reachedOn'] !== null) {
                $hit = null;
                foreach ($points as $pt) {
                    if ($pt['on'] === $progress['reachedOn']) {
                        $hit = $pt;
                    }
                }
                $add($progress['reachedOn'], 'meta_peso', 'Meta atingida: ' . $fmt((float) $hit['value']), $link);
            }
        }
    }

    /** "−3,1" com sinal de menos tipografico; "+0,5". */
    private static function signed(float $v, int $dec): string
    {
        $r = round($v, $dec);
        if ($r == 0.0) {
            return fmt_num(0.0, $dec);
        }
        return ($r > 0 ? '+' : '−') . fmt_num(abs($r), $dec);
    }
}
