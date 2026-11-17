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

    /** Cria ($id null) ou altera um modelo da biblioteca; mesma validacao de textos/categoria/frequencia das metas. */
    public function saveTemplate(?int $id, array $data, int $uid, Request $r): int
    {
        $before = null;
        if ($id !== null) {
            $before = $this->findTemplate($id);
            if ($before === null) {
                throw new InvalidGoalException(['Meta da biblioteca não encontrada.']);
            }
        }
        $row = $this->cleanTemplate($data);
        if ($id === null) {
            $newId = $this->db->insert('goal_templates', $row);
            $this->audit->record($uid, 'create', 'goal_templates', $newId, null, null, $row, $r);
            return $newId;
        }
        $sets = implode(', ', array_map(fn (string $c): string => $c . ' = ?', array_keys($row)));
        $this->db->run('UPDATE goal_templates SET ' . $sets . ' WHERE id = ?', [...array_values($row), $id]);
        $this->audit->record($uid, 'update', 'goal_templates', $id, null, $before, $this->findTemplate($id), $r);
        return $id;
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
        $row = $this->clean((int) $before['patient_id'], $data, (string) $before['start_date']);
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
        if ($endDate > $this->maxEnd((string) $before['start_date'])) {
            throw new InvalidGoalException(['A meta pode durar no máximo 5 anos.']);
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

    /**
     * Marca o dia para quem marcou: cada usuario tem a propria linha (UNIQUE goal_id, day, checked_by).
     * Se o usuario ja enxerga o dia marcado (por ele ou por outro visivel), nada muda.
     */
    public function check(int $goalId, string $day, array $user, string $today, Request $r): void
    {
        [$goal, $visible] = $this->markable($goalId, $day, $user, $today);
        if ($visible !== []) {
            return; // ja marcado para este usuario
        }
        $uid = (int) $user['id'];
        $stmt = $this->db->run(
            'INSERT IGNORE INTO goal_checks (goal_id, day, checked_by, checked_by_role) VALUES (?, ?, ?, ?)',
            [$goalId, $day, $uid, (string) $user['role']],
        );
        if ($stmt->rowCount() === 0) {
            return; // ja marcado (duplo clique / outra aba)
        }
        $row = $this->db->one('SELECT * FROM goal_checks WHERE goal_id = ? AND day = ? AND checked_by = ?', [$goalId, $day, $uid]);
        $this->audit->record($uid, 'check', 'goal_checks', (int) $row['id'], (int) $goal['patient_id'], null, $row, $r);
    }

    /** Desmarca o dia apagando so as linhas que o usuario enxerga (marcacao oculta da crianca fica intacta). */
    public function uncheck(int $goalId, string $day, array $user, string $today, Request $r): void
    {
        [$goal, $visible] = $this->markable($goalId, $day, $user, $today);
        foreach (array_unique(array_column($visible, 'checked_by')) as $by) {
            $row = $this->db->one('SELECT * FROM goal_checks WHERE goal_id = ? AND day = ? AND checked_by = ?', [$goalId, $day, $by]);
            if ($row === null) {
                continue;
            }
            $this->db->run('DELETE FROM goal_checks WHERE id = ?', [$row['id']]);
            $this->audit->record((int) $user['id'], 'uncheck', 'goal_checks', (int) $row['id'], (int) $goal['patient_id'], $row, null, $r);
        }
    }

    /** Meta que o usuario pode ver (nao excluida; sigilo e categorias como em load()); null = "nao encontrada". */
    public function visibleGoal(int $goalId, array $viewer): ?array
    {
        $goal = $this->find($goalId);
        if ($goal === null || $goal['deleted_at'] !== null) {
            return null;
        }
        $visible = match ((string) $viewer['role']) {
            'medico', 'crianca', 'paciente' => true,
            'nutricionista' => in_array($goal['category'], self::NUTRITIONAL, true),
            'responsavel' => (int) $goal['secret'] === 0,
            default => false,
        };
        return $visible ? $goal : null;
    }

    /** @return array{0:array, 1:list<array>} meta e marcacoes do dia que o usuario enxerga */
    private function markable(int $goalId, string $day, array $user, string $today): array
    {
        if (!in_array((string) $user['role'], ['responsavel', 'paciente', 'crianca'], true)) {
            throw new InvalidGoalException(['Só a família pode marcar as metas.']);
        }
        $goal = $this->visibleGoal($goalId, $user);
        if ($goal === null) {
            throw new InvalidGoalException(['Meta não encontrada.']);
        }
        if (!$this->isDate($day)) {
            throw new InvalidGoalException(['Dia inválido.']);
        }
        if (!GoalCalc::canMark($goal, $day, $today)) {
            throw new InvalidGoalException(['Só é possível marcar os últimos 7 dias, dentro do período da meta.']);
        }
        $view = $this->load((int) $goal['patient_id'], $user, $day, $day);
        $checks = array_values(array_filter($view['checks'], fn (array $c): bool => $c['goal_id'] === $goalId));
        return [$goal, $checks];
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
            $sql .= " AND category IN ('" . implode("','", self::NUTRITIONAL) . "')";
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

    private function maxEnd(string $start): string
    {
        return (new \DateTimeImmutable($start))->modify('+5 years')->format('Y-m-d');
    }

    private function isDate(mixed $v): bool
    {
        $d = is_string($v) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $v) : false;
        return $d !== false && $d->format('Y-m-d') === $v;
    }

    /**
     * Validacao comum a metas e modelos: textos, emoji, categoria e frequencia. Acrescenta mensagens a $errors.
     *
     * @param list<string> $errors
     * @return array{family_text:string, child_text:?string, emoji:string, category:string, times_per_week:int}
     */
    private function validateCommon(array $data, array &$errors): array
    {
        $family = trim((string) ($data['family_text'] ?? ''));
        if (mb_strlen($family) < 3 || mb_strlen($family) > 160) {
            $errors[] = 'O texto da meta deve ter de 3 a 160 caracteres.';
        }
        $child = trim((string) ($data['child_text'] ?? ''));
        if (mb_strlen($child) > 160) {
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
        return [
            'family_text' => $family,
            'child_text' => $child === '' ? null : $child,
            'emoji' => $emoji,
            'category' => $category,
            'times_per_week' => $tpw,
        ];
    }

    /** @return array<string,mixed> colunas validadas de goal_templates */
    private function cleanTemplate(array $data): array
    {
        $errors = [];
        $common = $this->validateCommon($data, $errors);
        $orderRaw = $data['sort_order'] ?? 0;
        $order = filter_var($orderRaw === '' ? 0 : $orderRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 9999]]);
        if ($order === false) {
            $errors[] = 'A ordem deve ser um número de 0 a 9999.';
        }
        if ($errors !== []) {
            throw new InvalidGoalException($errors);
        }
        return [
            ...$common,
            'active' => !empty($data['active']) ? 1 : 0,
            'sort_order' => (int) $order,
        ];
    }

    /**
     * Colunas validadas de patient_goals. Inicio entre hoje - 2 anos e hoje + 1 ano (exceto se nao mudou
     * numa edicao: $currentStart) e duracao de ate 5 anos.
     *
     * @return array<string,mixed>
     */
    private function clean(int $patientId, array $data, ?string $currentStart = null): array
    {
        $errors = [];
        $common = $this->validateCommon($data, $errors);
        $start = (string) ($data['start_date'] ?? '');
        if (!$this->isDate($start)) {
            $errors[] = 'Informe a data de início.';
        } elseif ($start !== $currentStart) {
            $today = new \DateTimeImmutable(date('Y-m-d'));
            if ($start < $today->modify('-2 years')->format('Y-m-d') || $start > $today->modify('+1 year')->format('Y-m-d')) {
                $errors[] = 'A data de início deve estar entre 2 anos atrás e 1 ano à frente.';
            }
        }
        $endRaw = $data['end_date'] ?? null;
        $end = ($endRaw === null || $endRaw === '') ? null : (string) $endRaw;
        if ($end !== null && !$this->isDate($end)) {
            $errors[] = 'Data de término inválida.';
        } elseif ($end !== null && $this->isDate($start) && $end < $start) {
            $errors[] = 'A data de término deve ser igual ou posterior ao início.';
        } elseif ($end !== null && $this->isDate($start) && $end > $this->maxEnd($start)) {
            $errors[] = 'A meta pode durar no máximo 5 anos.';
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
            ...$common,
            'start_date' => $start,
            'end_date' => $end,
            'secret' => $secret,
        ];
    }
}
