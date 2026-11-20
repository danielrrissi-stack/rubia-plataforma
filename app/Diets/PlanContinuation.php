<?php
declare(strict_types=1);

namespace App\Diets;

use App\Access\Gate;
use App\Access\Policy;
use App\Audit\AuditLog;
use App\Core\Config;
use App\Core\Database;
use App\Core\Mailer;
use App\Energy\EnergyGoalRepository;
use App\Growth\Age;
use App\Nutrition\FoodRepository;
use App\Nutrition\MealPlanRepository;
use App\Nutrition\MealPlanResolver;
use App\Nutrition\PlanDays;

/**
 * Continuacao do plano por dias (v2). Rodada diaria (cron/run.php), a partir das 06:00, uma vez por dia (chave
 * settings.plan_continuation_day travada com FOR UPDATE, como a ReminderRound): para cada plano publicado v2 com
 * 4 dias ou menos (contando hoje) e sem continuation_draft_id, monta um rascunho com os dias restantes + plan_days
 * dias novos (meta e restricoes atuais) e avisa por e-mail quem publicou (ou os medicos ativos). Paciente com rascunho
 * aberto: so o aviso, uma vez (continuation_draft_id = 0).
 */
final class PlanContinuation
{
    public const KEY = 'plan_continuation_day';
    private const FROM = '06:00';
    private const MAX_REMAINING = 4;

    public function __construct(
        private Database $db,
        private MealPlanRepository $plans,
        private EnergyGoalRepository $goals,
        private FoodRepository $foods,
        private Mailer $mailer,
        private Gate $gate,
        private AuditLog $audit,
    ) {
    }

    /** $now 'Y-m-d H:i'. Devolve quantos rascunhos foram criados. */
    public function runIfDue(string $now): int
    {
        if (substr($now, 11, 5) < self::FROM) {
            return 0;
        }
        $today = substr($now, 0, 10);
        // 1) reserva o dia numa transacao curta (duas rodadas sobrepostas: so uma passa)
        $this->db->run('INSERT IGNORE INTO settings (`key`, value) VALUES (?, NULL)', [self::KEY]);
        $claimed = (bool) $this->db->transaction(function () use ($today): bool {
            $row = $this->db->one('SELECT value FROM settings WHERE `key` = ? FOR UPDATE', [self::KEY]);
            if ($row !== null && (string) $row['value'] === $today) {
                return false;
            }
            $this->db->run('UPDATE settings SET value = ? WHERE `key` = ?', [$today, self::KEY]);
            return true;
        });
        if (!$claimed) {
            return 0;
        }
        // 2) cada plano na sua transacao: um plano com erro nunca trava os outros (o marcador dele fica NULL e ele
        //    volta na rodada do dia seguinte)
        $count = 0;
        foreach ($this->due($today) as $plan) {
            try {
                $created = (bool) $this->db->transaction(fn(): bool => $this->processPlan($plan, $today));
                $count += $created ? 1 : 0;
            } catch (\Throwable $e) {
                error_log('[continuacao] plano ' . (int) $plan['id'] . ': ' . get_class($e) . ': ' . $e->getMessage());
            }
        }
        return $count;
    }

    /** Um plano da rodada. true quando criou o rascunho de continuacao. */
    private function processPlan(array $plan, string $today): bool
    {
        $planId = (int) $plan['id'];
        $draft = $this->plans->draft((int) $plan['patient_id']);
        if ($draft !== null) {
            $this->db->run('UPDATE meal_plans SET continuation_draft_id = 0 WHERE id = ? AND continuation_draft_id IS NULL', [$planId]);
            $this->notify($plan, (int) $draft['id'], true);
            return false;
        }
        $by = $plan['published_by'] !== null ? (int) $plan['published_by'] : $this->firstDoctorId();
        if ($by === null) {
            error_log('[continuacao] plano ' . $planId . ': sem quem publicou e sem médico ativo; não gerado.');
            return false;
        }
        $draftId = $this->generate($planId, $by, true, $today);
        $this->notify($plan, $draftId, false);
        return true;
    }

    private function firstDoctorId(): ?int
    {
        $row = $this->db->one("SELECT id FROM users WHERE role = 'medico' AND active = 1 ORDER BY id LIMIT 1");
        return $row === null ? null : (int) $row['id'];
    }

    /** @return list<array> planos publicados v2 (decodificados) com <= 4 dias restantes e sem continuacao */
    public function due(string $today): array
    {
        $rows = $this->db->all(
            "SELECT mp.* FROM meal_plans mp JOIN patients p ON p.id = mp.patient_id
             WHERE mp.status = 'publicado' AND mp.mode = 'estruturado' AND mp.continuation_draft_id IS NULL
               AND mp.structure_json IS NOT NULL AND p.deleted_at IS NULL
             ORDER BY mp.id",
        );
        $out = [];
        foreach ($rows as $row) {
            try {
                $structure = json_decode((string) $row['structure_json'], true);
                if (is_array($structure) && PlanDays::isMulti($structure)
                    && PlanDays::remainingFrom($structure, $today) <= self::MAX_REMAINING) {
                    $row['structure'] = $structure;
                    $out[] = $row;
                }
            } catch (\Throwable $e) {
                error_log('[continuacao] plano ' . (int) $row['id'] . ': ' . get_class($e) . ': ' . $e->getMessage());
            }
        }
        return $out;
    }

    /**
     * Planos publicados v2 que terminam em ate 4 dias (ou ja terminaram) para o cartao do painel.
     * @return list<array{patient_id:int, plan_id:int, name:string, kind:string, end:string, url:string, draft_url:?string, can_edit:bool}>
     */
    public function reviewList(array $user, string $today): array
    {
        $ids = \App\Core\Container::patientScope()->accessiblePatientIds($user);
        if ($ids === []) {
            return [];
        }
        $sql = "SELECT mp.id, mp.patient_id, mp.structure_json, mp.continuation_draft_id, p.full_name, p.social_name
                FROM meal_plans mp JOIN patients p ON p.id = mp.patient_id
                WHERE mp.status = 'publicado' AND mp.mode = 'estruturado' AND mp.discarded_at IS NULL
                  AND mp.structure_json IS NOT NULL AND p.deleted_at IS NULL";
        $params = [];
        if ($ids !== null) {
            $sql .= ' AND mp.patient_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $params = array_values($ids);
        }
        $out = [];
        foreach ($this->db->all($sql . ' ORDER BY mp.id', $params) as $row) {
            $pid = (int) $row['patient_id'];
            $structure = json_decode((string) $row['structure_json'], true);
            if (!is_array($structure) || !PlanDays::isMulti($structure)
                || PlanDays::remainingFrom($structure, $today) > self::MAX_REMAINING) {
                continue;
            }
            $last = PlanDays::lastDate($structure);
            if ($last === null || !$this->gate->check($user, 'view', Policy::NUTRICAO, $pid)) {
                continue;
            }
            $marker = $row['continuation_draft_id'] === null ? null : (int) $row['continuation_draft_id'];
            $draftId = null;
            if ($marker !== null && $marker > 0) {
                $d = $this->db->one(
                    "SELECT id FROM meal_plans WHERE id = ? AND status = 'rascunho' AND discarded_at IS NULL",
                    [$marker]
                );
                $draftId = $d === null ? null : (int) $d['id'];
            } else {
                $open = $this->plans->draft($pid);
                $draftId = $open === null ? null : (int) $open['id'];
            }
            $ended = $today > $last;
            if ($ended) {
                $kind = 'vencido';
            } elseif ($marker !== null && $marker > 0) {
                $kind = $draftId !== null ? 'pendente' : 'sem_continuacao';
            } else {
                $kind = $draftId !== null ? 'rascunho_aberto' : 'sem_continuacao';
            }
            $end = $ended ? (new \DateTimeImmutable($last))->modify('+1 day')->format('Y-m-d') : $last;
            $out[] = [
                'patient_id' => $pid,
                'plan_id' => (int) $row['id'],
                'name' => (string) (($row['social_name'] ?? '') ?: $row['full_name']),
                'kind' => $kind,
                'end' => $end,
                'url' => '/pacientes/' . $pid . '/plano',
                'draft_url' => $draftId !== null ? '/planos/' . $draftId . '/editar' : null,
                'can_edit' => $this->gate->check($user, 'edit', Policy::NUTRICAO, $pid),
            ];
        }
        usort($out, fn(array $x, array $y): int => [$x['kind'] === 'vencido' ? 0 : 1, $x['end']] <=> [$y['kind'] === 'vencido' ? 0 : 1, $y['end']]);
        return $out;
    }

    /**
     * Monta o rascunho de continuacao do plano publicado v2 $planId e devolve o id.
     * @throws \RuntimeException (inclui InvalidPlanException)
     */
    public function generate(int $planId, int $by, bool $auto, string $today): int
    {
        $plan = $this->plans->find($planId);
        if ($plan === null || $plan['status'] !== 'publicado' || !is_array($plan['structure']) || !PlanDays::isMulti($plan['structure'])) {
            throw new \RuntimeException('Só é possível continuar um plano por dias publicado.');
        }
        $pid = (int) $plan['patient_id'];
        if ($this->plans->draft($pid) !== null) {
            throw new \RuntimeException('Já existe um rascunho aberto.');
        }
        $patient = $this->db->one('SELECT birth_date FROM patients WHERE id = ? AND deleted_at IS NULL', [$pid]);
        if ($patient === null) {
            throw new \RuntimeException('Paciente não encontrado.');
        }
        $band = DietBuilder::bandForAge(Age::years((string) $patient['birth_date'], $today));
        if ($band === null) {
            throw new \RuntimeException('As dietas prontas são para pacientes a partir de 3 anos.');
        }
        $goal = $this->goals->current($pid);
        $base = $goal === null ? null : (int) $goal['get_kcal'];
        if ($base === null && preg_match('/^(\d+)kcal/', (string) $plan['source_diet_code'], $m) === 1) {
            $base = (int) $m[1];
        }
        $kcal = DietController::suggestedKcal($band, $base);

        $structure = $plan['structure'];
        $start = max($today, (string) $structure['start']);
        $remaining = [];
        foreach (PlanDays::days($structure) as $day) {
            if ($day['date'] >= $start) {
                $remaining[] = ['meals' => $day['meals']];
            }
        }
        $n = $plan['plan_days'] === null ? 14 : (int) $plan['plan_days'];
        $newStart = (new \DateTimeImmutable($start))->modify('+' . count($remaining) . ' days')->format('Y-m-d');
        $sug = (new DietController())->buildSuggestion($pid, $band, $kcal, $newStart, $n);
        $combined = (new MealPlanResolver($this->foods))->resolve(
            ['v' => 2, 'start' => $start, 'days' => array_merge($remaining, $sug['structure']['days'])],
            true,
        );
        $last = (string) PlanDays::lastDate($combined);
        $title = 'Plano alimentar – ' . (new \DateTimeImmutable($start))->format('d/m') . ' a ' . (new \DateTimeImmutable($last))->format('d/m');

        return (int) $this->db->transaction(function () use ($planId, $pid, $by, $auto, $plan, $combined, $title, $sug, $n): int {
            $this->db->one('SELECT id FROM patients WHERE id = ? FOR UPDATE', [$pid]);
            $src = $this->db->one('SELECT status, continuation_draft_id FROM meal_plans WHERE id = ? FOR UPDATE', [$planId]);
            if ($src === null || $src['status'] !== 'publicado') {
                throw new \RuntimeException('Só é possível continuar um plano por dias publicado.');
            }
            if ($auto && $src['continuation_draft_id'] !== null) {
                // a rodada nunca refaz: continuacao ja gerada (talvez descartada de proposito) ou aviso ja enviado
                throw new \RuntimeException('A continuação deste plano já foi tratada.');
            }
            try {
                $draftId = $this->plans->createDraft($pid, 'estruturado', [
                    'title' => $title, 'body_text' => null,
                    'guidance_text' => $plan['guidance_text'], 'structure' => $combined,
                ], $by, null, null, $planId, $sug['code'], $n, $auto);
            } catch (\PDOException $e) {
                throw $e;
            } catch (\RuntimeException) {
                throw new \RuntimeException('Já existe um rascunho aberto.');
            }
            $this->db->run('UPDATE meal_plans SET continuation_draft_id = ? WHERE id = ?', [$draftId, $planId]);
            $this->audit->record($by, 'continuation', 'meal_plans', $planId, $pid, null, [
                'continuation_draft_id' => $draftId, 'meta' => ['auto' => $auto],
            ]);
            return $draftId;
        });
    }

    /** E-mail para quem publicou (ativo e com acesso a NUTRICAO) ou, senao, para os medicos ativos com acesso. */
    private function notify(array $plan, int $draftId, bool $openDraft): void
    {
        $pid = (int) $plan['patient_id'];
        $patient = $this->db->one('SELECT full_name, social_name FROM patients WHERE id = ?', [$pid]);
        $name = trim((string) (($patient['social_name'] ?? '') ?: ($patient['full_name'] ?? '')));
        $first = $name === '' ? '' : (string) preg_split('/\s+/u', $name)[0];
        $end = fmt_date(PlanDays::lastDate($plan['structure']));
        $link = rtrim((string) Config::get('base_url', ''), '/') . '/planos/' . $draftId . '/editar';
        $subject = 'Plano alimentar de ' . $first . ' para revisar';
        $html = $openDraft
            ? '<p>O plano alimentar de ' . e($first) . ' termina em ' . e($end) . ' e há um rascunho aberto.</p>'
                . '<p><a href="' . e($link) . '">Abrir o rascunho</a></p>'
            : '<p>O plano alimentar de ' . e($first) . ' termina em ' . e($end) . '.</p>'
                . '<p>Os próximos dias já foram montados como rascunho para você revisar e publicar: <a href="' . e($link) . '">abrir o rascunho</a>.</p>';
        $recipients = $this->recipients($plan);
        if ($recipients === []) {
            error_log('[continuacao] plano ' . (int) $plan['id'] . ': nenhum destinatário para o aviso por e-mail.');
        }
        foreach ($recipients as $to) {
            $this->mailer->queue($to, $subject, $html);
        }
    }

    /** @return list<string> */
    private function recipients(array $plan): array
    {
        $pid = (int) $plan['patient_id'];
        $ok = fn(array $u): bool => (int) $u['active'] === 1 && trim((string) $u['email']) !== ''
            && $this->gate->check($u, 'edit', Policy::NUTRICAO, $pid);
        if ($plan['published_by'] !== null) {
            $pub = $this->db->one('SELECT * FROM users WHERE id = ?', [(int) $plan['published_by']]);
            if ($pub !== null && $ok($pub)) {
                return [trim((string) $pub['email'])];
            }
        }
        $out = [];
        foreach ($this->db->all("SELECT * FROM users WHERE role = 'medico' AND active = 1 ORDER BY id") as $u) {
            if ($ok($u)) {
                $email = trim((string) $u['email']);
                $out[mb_strtolower($email)] ??= $email;
            }
        }
        return array_values($out);
    }
}
