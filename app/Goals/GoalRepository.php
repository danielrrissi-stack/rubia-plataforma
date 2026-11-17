<?php
declare(strict_types=1);

namespace App\Goals;

use App\Audit\AuditLog;
use App\Core\Database;
use App\Core\Request;

/** Metas dos pacientes e marcacoes diarias. forViewer() e a unica regra de visibilidade. */
final class GoalRepository
{
    public const CATEGORIES = ['alimentacao', 'hidratacao', 'sono', 'atividade', 'telas', 'rotina'];
    public const NUTRITIONAL = ['alimentacao', 'hidratacao'];

    public function __construct(private Database $db, private AuditLog $audit)
    {
    }

    /** @return list<array> */
    public function templates(bool $activeOnly = true): array
    {
        return $this->db->all(
            'SELECT * FROM goal_templates' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY sort_order, id'
        );
    }

    public function findTemplate(int $id): ?array
    {
        return $this->db->one('SELECT * FROM goal_templates WHERE id = ?', [$id]);
    }

    public function find(int $goalId): ?array
    {
        return $this->db->one('SELECT * FROM patient_goals WHERE id = ?', [$goalId]);
    }

    public function create(int $patientId, array $data, int $uid, Request $r): int
    {
        $row = $this->clean($patientId, $data);
        $id = $this->db->insert('patient_goals', ['patient_id' => $patientId, 'created_by' => $uid] + $row);
        $this->audit->record($uid, 'create', 'patient_goals', $id, $patientId, null, $this->find($id), $r);
        return $id;
    }

    public function update(int $goalId, array $data, int $uid, Request $r): void
    {
        $before = $this->active($goalId);
        $row = $this->clean((int) $before['patient_id'], $data);
        $sets = implode(', ', array_map(fn (string $c): string => $c . ' = ?', array_keys($row)));
        $this->db->run(
            'UPDATE patient_goals SET ' . $sets . ', updated_by = ?, updated_at = NOW() WHERE id = ?',
            [...array_values($row), $uid, $goalId],
        );
        $this->audit->record($uid, 'update', 'patient_goals', $goalId, (int) $before['patient_id'], $before, $this->find($goalId), $r);
    }

    public function end(int $goalId, string $endDate, int $uid, Request $r): void
    {
        $before = $this->active($goalId);
        if (!$this->isDate($endDate) || $endDate < $before['start_date']) {
            throw new InvalidGoalException(['A data de término deve ser igual ou posterior ao início.']);
        }
        $this->db->run(
            'UPDATE patient_goals SET end_date = ?, updated_by = ?, updated_at = NOW() WHERE id = ?',
            [$endDate, $uid, $goalId],
        );
        $this->audit->record($uid, 'end', 'patient_goals', $goalId, (int) $before['patient_id'], $before, $this->find($goalId), $r);
    }

    public function delete(int $goalId, int $uid, Request $r): void
    {
        $before = $this->active($goalId);
        $this->db->run(
            'UPDATE patient_goals SET deleted_at = NOW(), deleted_by = ?, updated_by = ?, updated_at = NOW() WHERE id = ?',
            [$uid, $uid, $goalId],
        );
        $this->audit->record($uid, 'delete', 'patient_goals', $goalId, (int) $before['patient_id'], $before, $this->find($goalId), $r);
    }

    /**
     * Metas (e marcacoes) que o usuario pode ver no periodo [from, to].
     *
     * @return array{goals: list<array>, checks: list<array{goal_id:int, day:string, checked_by:int, checked_by_role:string}>}
     */
    public function forViewer(int $patientId, array $viewer, string $from, string $to): array
    {
        return $this->load($patientId, $viewer, $from, $to);
    }

    /**
     * Todas as metas visiveis e marcacoes desde sempre (estrelas e selos).
     *
     * @return array{goals: list<array>, checks: list<array{goal_id:int, day:string, checked_by:int, checked_by_role:string}>}
     */
    public function allForViewer(int $patientId, array $viewer): array
    {
        return $this->load($patientId, $viewer, null, null);
    }

    public function check(int $goalId, string $day, array $user, string $today, Request $r): void
    {
        $goal = $this->markable($goalId, $day, $user, $today);
        $stmt = $this->db->run(
            'INSERT IGNORE INTO goal_checks (goal_id, day, checked_by, checked_by_role) VALUES (?, ?, ?, ?)',
            [$goalId, $day, (int) $user['id'], (string) $user['role']],
        );
        if ($stmt->rowCount() === 0) {
            return; // ja marcado (duplo clique / outra aba)
        }
        $row = $this->db->one('SELECT * FROM goal_checks WHERE goal_id = ? AND day = ?', [$goalId, $day]);
        $this->audit->record((int) $user['id'], 'check', 'goal_checks', (int) $row['id'], (int) $goal['patient_id'], null, $row, $r);
    }

    public function uncheck(int $goalId, string $day, array $user, string $today, Request $r): void
    {
        $goal = $this->markable($goalId, $day, $user, $today);
        $row = $this->db->one('SELECT * FROM goal_checks WHERE goal_id = ? AND day = ?', [$goalId, $day]);
        if ($row === null) {
            return;
        }
        $this->db->run('DELETE FROM goal_checks WHERE id = ?', [$row['id']]);
        $this->audit->record((int) $user['id'], 'uncheck', 'goal_checks', (int) $row['id'], (int) $goal['patient_id'], $row, null, $r);
    }

    private function markable(int $goalId, string $day, array $user, string $today): array
    {
        $goal = $this->find($goalId);
        if ($goal === null || $goal['deleted_at'] !== null || !$this->isDate($day)) {
            throw new InvalidGoalException(['Meta não encontrada.']);
        }
        $visible = $this->load((int) $goal['patient_id'], $user, $day, $day)['goals'];
        if (!in_array($goalId, array_map(fn (array $g): int => (int) $g['id'], $visible), true)) {
            throw new InvalidGoalException(['Meta não encontrada.']);
        }
        if (!GoalCalc::canMark($goal, $day, $today)) {
            throw new InvalidGoalException(['Só é possível marcar os últimos 7 dias, dentro do período da meta.']);
        }
        return $goal;
    }

    private function load(int $patientId, array $viewer, ?string $from, ?string $to): array
    {
        $role = (string) $viewer['role'];
        $sql = 'SELECT * FROM patient_goals WHERE patient_id = ? AND deleted_at IS NULL';
        $params = [$patientId];
        if ($from !== null && $to !== null) {
            $sql .= ' AND start_date <= ? AND (end_date IS NULL OR end_date >= ?)';
            array_push($params, $to, $from);
        }
        if ($role === 'nutricionista') {
            $sql .= " AND category IN ('alimentacao','hidratacao')";
        } elseif ($role === 'responsavel') {
            $sql .= ' AND secret = 0';
        } elseif (!in_array($role, ['medico', 'crianca', 'paciente'], true)) {
            return ['goals' => [], 'checks' => []];
        }
        $goals = $this->db->all($sql . ' ORDER BY id', $params);
        if ($goals === []) {
            return ['goals' => [], 'checks' => []];
        }

        $in = implode(',', array_fill(0, count($goals), '?'));
        $csql = 'SELECT goal_id, day, checked_by, checked_by_role FROM goal_checks WHERE goal_id IN (' . $in . ')';
        $cparams = array_map(fn (array $g): int => (int) $g['id'], $goals);
        if ($from !== null && $to !== null) {
            $csql .= ' AND day BETWEEN ? AND ?';
            array_push($cparams, $from, $to);
        }
        if ($role === 'responsavel') {
            $patient = $this->db->one('SELECT child_user_id, guardians_see_child_checks FROM patients WHERE id = ?', [$patientId]);
            if ($patient !== null && $patient['child_user_id'] !== null && (int) $patient['guardians_see_child_checks'] === 0) {
                $csql .= ' AND checked_by <> ?';
                $cparams[] = (int) $patient['child_user_id'];
            }
        }
        $checks = array_map(fn (array $c): array => [
            'goal_id' => (int) $c['goal_id'],
            'day' => (string) $c['day'],
            'checked_by' => (int) $c['checked_by'],
            'checked_by_role' => (string) $c['checked_by_role'],
        ], $this->db->all($csql . ' ORDER BY day, goal_id', $cparams));
        return ['goals' => $goals, 'checks' => $checks];
    }

    private function active(int $goalId): array
    {
        $goal = $this->find($goalId);
        if ($goal === null || $goal['deleted_at'] !== null) {
            throw new InvalidGoalException(['Meta não encontrada.']);
        }
        return $goal;
    }

    private function isDate(mixed $v): bool
    {
        $d = is_string($v) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $v) : false;
        return $d !== false && $d->format('Y-m-d') === $v;
    }

    /** @return array<string,mixed> colunas validadas de patient_goals */
    private function clean(int $patientId, array $data): array
    {
        $errors = [];
        $family = trim((string) ($data['family_text'] ?? ''));
        $len = mb_strlen($family);
        if ($len < 3 || $len > 160) {
            $errors[] = 'O texto da meta deve ter de 3 a 160 caracteres.';
        }
        $child = trim((string) ($data['child_text'] ?? ''));
        if ($child !== '' && mb_strlen($child) > 160) {
            $errors[] = 'O texto da criança deve ter até 160 caracteres.';
        }
        $emoji = trim((string) ($data['emoji'] ?? ''));
        if ($emoji === '' || mb_strlen($emoji) > 16) {
            $errors[] = 'Escolha um emoji para a meta.';
        }
        $category = (string) ($data['category'] ?? '');
        if (!in_array($category, self::CATEGORIES, true)) {
            $errors[] = 'Escolha uma categoria válida.';
        }
        $tpwRaw = $data['times_per_week'] ?? null;
        $tpw = is_int($tpwRaw) || (is_string($tpwRaw) && ctype_digit($tpwRaw)) ? (int) $tpwRaw : 0;
        if ($tpw < 1 || $tpw > 7) {
            $errors[] = 'A frequência deve ser de 1 a 7 dias por semana.';
        }
        $start = (string) ($data['start_date'] ?? '');
        if (!$this->isDate($start)) {
            $errors[] = 'Informe a data de início.';
        }
        $endRaw = $data['end_date'] ?? null;
        $end = ($endRaw === null || $endRaw === '') ? null : (string) $endRaw;
        if ($end !== null && !$this->isDate($end)) {
            $errors[] = 'Data de término inválida.';
        } elseif ($end !== null && $this->isDate($start) && $end < $start) {
            $errors[] = 'A data de término deve ser igual ou posterior ao início.';
        }
        $secret = !empty($data['secret']) ? 1 : 0;
        if ($secret === 1) {
            $p = $this->db->one('SELECT child_user_id FROM patients WHERE id = ?', [$patientId]);
            if ($p === null || $p['child_user_id'] === null) {
                $errors[] = 'Meta secreta só é possível quando o paciente tem conta de criança.';
            }
        }
        $tid = $data['template_id'] ?? null;
        if ($tid !== null && $tid !== '' && $this->findTemplate((int) $tid) === null) {
            $errors[] = 'Meta da biblioteca não encontrada.';
        }
        if ($errors !== []) {
            throw new InvalidGoalException($errors);
        }
        return [
            'template_id' => ($tid === null || $tid === '') ? null : (int) $tid,
            'family_text' => $family,
            'child_text' => $child === '' ? null : $child,
            'emoji' => $emoji,
            'category' => $category,
            'times_per_week' => $tpw,
            'start_date' => $start,
            'end_date' => $end,
            'secret' => $secret,
        ];
    }
}
