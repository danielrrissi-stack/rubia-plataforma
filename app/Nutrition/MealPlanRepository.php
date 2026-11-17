<?php
declare(strict_types=1);

namespace App\Nutrition;

use App\Audit\AuditLog;
use App\Core\Database;
use App\Core\Request;

/** Ciclo de vida do plano alimentar: rascunho -> publicado -> substituido/encerrado. */
final class MealPlanRepository
{
    public function __construct(private Database $db, private AuditLog $audit)
    {
    }

    public function createDraft(
        int $patientId,
        string $mode,
        array $data,
        int $by,
        ?Request $r = null,
        ?int $sourceTemplateId = null,
        ?int $sourcePlanId = null,
    ): int {
        return $this->db->transaction(function () use ($patientId, $mode, $data, $by, $r, $sourceTemplateId, $sourcePlanId): int {
            $this->lockPatient($patientId);
            $existing = $this->db->one(
                "SELECT id FROM meal_plans WHERE patient_id = ? AND status = 'rascunho' AND discarded_at IS NULL FOR UPDATE",
                [$patientId],
            );
            if ($existing !== null) {
                throw new \RuntimeException('Já existe um rascunho para este paciente.');
            }
            $row = [
                'patient_id' => $patientId,
                'mode' => $mode,
                'title' => trim((string) ($data['title'] ?? '')),
                'status' => 'rascunho',
                'body_text' => $this->nullIfEmpty($data['body_text'] ?? null),
                'guidance_text' => $this->nullIfEmpty($data['guidance_text'] ?? null),
                'structure_json' => $this->encode($data['structure'] ?? null),
                'source_template_id' => $sourceTemplateId,
                'source_plan_id' => $sourcePlanId,
                'created_by' => $by,
            ];
            $id = $this->db->insert('meal_plans', $row);
            $this->audit->record($by, 'create', 'meal_plans', $id, $patientId, null, [
                'mode' => $mode,
                'source_template_id' => $sourceTemplateId, 'source_plan_id' => $sourcePlanId,
            ] + $this->content($row['title'], $row['body_text'], $row['guidance_text'], $data['structure'] ?? null), $r);
            return $id;
        });
    }

    public function draftFrom(int $publishedPlanId, int $by, ?Request $r = null): int
    {
        $pid = $this->patientIdOf($publishedPlanId);
        if ($pid === null) {
            throw new \RuntimeException('Plano não encontrado.');
        }
        return $this->db->transaction(function () use ($publishedPlanId, $pid, $by, $r): int {
            $this->lockPatient($pid);
            $src = $this->decode($this->db->one('SELECT * FROM meal_plans WHERE id = ? FOR UPDATE', [$publishedPlanId]));
            if ($src === null || $src['status'] !== 'publicado') {
                throw new \RuntimeException('Só é possível editar a partir de um plano publicado.');
            }
            return $this->createDraft($pid, (string) $src['mode'], [
                'title' => $src['title'],
                'body_text' => $src['body_text'],
                'guidance_text' => $src['guidance_text'],
                'structure' => $src['structure'],
            ], $by, $r, null, $publishedPlanId);
        });
    }

    /** @return array{revision:int,conflict:bool} */
    public function saveDraft(int $planId, array $data, int $by, ?Request $r = null, bool $auto = false, ?int $baseRevision = null): array
    {
        return $this->db->transaction(function () use ($planId, $data, $by, $r, $auto, $baseRevision): array {
            $plan = $this->db->one('SELECT * FROM meal_plans WHERE id = ? FOR UPDATE', [$planId]);
            if ($plan === null || $plan['status'] !== 'rascunho' || $plan['discarded_at'] !== null) {
                throw new \RuntimeException('Só é possível editar um rascunho.');
            }
            $prev = (int) $plan['revision'];
            $conflict = $baseRevision !== null && $baseRevision !== $prev;
            $new = $prev + 1;
            $title = trim((string) ($data['title'] ?? ''));
            $this->db->run(
                'UPDATE meal_plans SET title = ?, body_text = ?, guidance_text = ?, structure_json = ?,
                        revision = ?, updated_by = ?, updated_at = NOW() WHERE id = ?',
                [
                    $title,
                    $this->nullIfEmpty($data['body_text'] ?? null),
                    $this->nullIfEmpty($data['guidance_text'] ?? null),
                    $this->encode($data['structure'] ?? null),
                    $new, $by, $planId,
                ],
            );
            $due = $this->db->one(
                'SELECT (last_audited_at IS NULL OR last_audited_at < NOW() - INTERVAL 10 MINUTE) AS due
                 FROM meal_plans WHERE id = ?',
                [$planId],
            );
            if (!$auto || (int) $due['due'] === 1) {
                $this->db->run('UPDATE meal_plans SET last_audited_at = NOW() WHERE id = ?', [$planId]);
                $this->audit->record(
                    $by, 'update', 'meal_plans', $planId, (int) $plan['patient_id'],
                    ['revision' => $prev, 'title' => $plan['title']],
                    ['revision' => $new, 'auto' => $auto, 'conflict' => $conflict]
                    + $this->content($title, $this->nullIfEmpty($data['body_text'] ?? null), $this->nullIfEmpty($data['guidance_text'] ?? null), $data['structure'] ?? null),
                    $r,
                );
            }
            return ['revision' => $new, 'conflict' => $conflict];
        });
    }

    public function publish(int $planId, int $by, ?Request $r = null): void
    {
        $this->db->transaction(function () use ($planId, $by, $r): void {
            $pid = $this->patientIdOf($planId);
            if ($pid === null) {
                throw new \RuntimeException('Plano não encontrado.');
            }
            $this->lockPatient($pid);
            $plan = $this->decode($this->db->one('SELECT * FROM meal_plans WHERE id = ? FOR UPDATE', [$planId]));
            if ($plan['status'] !== 'rascunho' || $plan['discarded_at'] !== null) {
                throw new \RuntimeException('Só é possível publicar um rascunho.');
            }
            if (trim((string) $plan['title']) === '') {
                throw new \RuntimeException('Informe um título para o plano.');
            }

            $totals = [null, null, null, null, null];
            $structure = $plan['structure'];
            if ($plan['mode'] === 'estruturado') {
                $meals = is_array($structure) ? ($structure['meals'] ?? []) : [];
                if ($meals === []) {
                    throw new \RuntimeException('Plano vazio: adicione ao menos uma refeição.');
                }
                foreach ($meals as $meal) {
                    $items = $meal['options'][0]['items'] ?? [];
                    $note = trim((string) ($meal['note'] ?? ''));
                    if ($items === [] && $note === '') {
                        throw new \RuntimeException('Refeição sem itens: ' . ($meal['name'] ?? '') . '.');
                    }
                }
                $d = MealPlanCalculator::day($structure);
                $totals = [
                    round((float) $d['kcal'], 1), round((float) $d['protein'], 1), round((float) $d['carb'], 1),
                    round((float) $d['fat'], 1), round((float) $d['fiber'], 1),
                ];
            } elseif (trim((string) $plan['body_text']) === '') {
                throw new \RuntimeException('Plano vazio: escreva o plano antes de publicar.');
            }

            $this->db->run(
                "UPDATE meal_plans SET status = 'substituido', superseded_at = NOW()
                 WHERE patient_id = ? AND status = 'publicado'",
                [$pid],
            );
            $this->db->run(
                "UPDATE meal_plans SET status = 'publicado', published_by = ?, published_at = NOW(),
                        total_kcal = ?, total_protein_g = ?, total_carb_g = ?, total_fat_g = ?, total_fiber_g = ?
                 WHERE id = ?",
                [$by, ...$totals, $planId],
            );
            $this->audit->record(
                $by, 'publish', 'meal_plans', $planId, $pid,
                ['status' => 'rascunho'],
                ['status' => 'publicado', 'revision' => (int) $plan['revision'], 'total_kcal' => $totals[0]]
                + $this->content((string) $plan['title'], $plan['body_text'], $plan['guidance_text'], $structure),
                $r,
            );
        });
    }

    public function end(int $planId, string $reason, int $by, ?Request $r = null): void
    {
        $reason = trim($reason);
        $len = mb_strlen($reason);
        if ($len < 3 || $len > 255) {
            throw new \RuntimeException('Informe o motivo do encerramento (3 a 255 caracteres).');
        }
        $this->db->transaction(function () use ($planId, $reason, $by, $r): void {
            $plan = $this->db->one('SELECT * FROM meal_plans WHERE id = ? FOR UPDATE', [$planId]);
            if ($plan === null || $plan['status'] !== 'publicado') {
                throw new \RuntimeException('Só é possível encerrar um plano publicado.');
            }
            $this->db->run(
                "UPDATE meal_plans SET status = 'encerrado', ended_by = ?, ended_at = NOW(), ended_reason = ? WHERE id = ?",
                [$by, $reason, $planId],
            );
            $this->audit->record(
                $by, 'end', 'meal_plans', $planId, (int) $plan['patient_id'],
                ['status' => 'publicado'], ['status' => 'encerrado', 'reason' => $reason], $r,
            );
        });
    }

    public function discard(int $planId, int $by, ?Request $r = null): void
    {
        $this->db->transaction(function () use ($planId, $by, $r): void {
            $plan = $this->db->one('SELECT * FROM meal_plans WHERE id = ? FOR UPDATE', [$planId]);
            if ($plan === null || $plan['status'] !== 'rascunho' || $plan['discarded_at'] !== null) {
                throw new \RuntimeException('Só é possível descartar um rascunho.');
            }
            $this->db->run('UPDATE meal_plans SET discarded_at = NOW() WHERE id = ?', [$planId]);
            $this->audit->record(
                $by, 'discard', 'meal_plans', $planId, (int) $plan['patient_id'],
                ['title' => $plan['title']], null, $r,
            );
        });
    }

    public function find(int $id): ?array
    {
        return $this->decode($this->db->one('SELECT * FROM meal_plans WHERE id = ?', [$id]));
    }

    public function draft(int $patientId): ?array
    {
        return $this->decode($this->db->one(
            "SELECT * FROM meal_plans WHERE patient_id = ? AND status = 'rascunho' AND discarded_at IS NULL
             ORDER BY id DESC LIMIT 1",
            [$patientId],
        ));
    }

    public function current(int $patientId): ?array
    {
        return $this->decode($this->db->one(
            "SELECT * FROM meal_plans WHERE patient_id = ? AND status = 'publicado' ORDER BY id DESC LIMIT 1",
            [$patientId],
        ));
    }

    /** @return list<array> */
    public function history(int $patientId): array
    {
        $rows = $this->db->all(
            "SELECT p.*, a.name AS author, pu.name AS publisher, en.name AS ender
             FROM meal_plans p
             JOIN users a ON a.id = p.created_by
             LEFT JOIN users pu ON pu.id = p.published_by
             LEFT JOIN users en ON en.id = p.ended_by
             WHERE p.patient_id = ? AND NOT (p.status = 'rascunho' AND p.discarded_at IS NOT NULL)
             ORDER BY p.id DESC",
            [$patientId],
        );
        return array_map(fn(array $x): array => $this->decode($x), $rows);
    }

    /** Lê patient_id fora do snapshot da transação (leitura simples antes de travar). */
    private function patientIdOf(int $planId): ?int
    {
        $row = $this->db->one('SELECT patient_id FROM meal_plans WHERE id = ?', [$planId]);
        return $row === null ? null : (int) $row['patient_id'];
    }

    private function content(string $title, ?string $body, ?string $guidance, mixed $structure): array
    {
        return ['title' => $title, 'body_text' => $body, 'guidance_text' => $guidance, 'structure' => $structure];
    }

    private function lockPatient(int $patientId): void
    {
        $this->db->one('SELECT id FROM patients WHERE id = ? FOR UPDATE', [$patientId]);
    }

    private function decode(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        $row['structure'] = ($row['structure_json'] ?? null) !== null
            ? json_decode((string) $row['structure_json'], true)
            : null;
        return $row;
    }

    private function encode(mixed $structure): ?string
    {
        return $structure === null ? null : json_encode($structure, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function nullIfEmpty(mixed $v): ?string
    {
        $v = $v === null ? '' : (string) $v;
        return trim($v) === '' ? null : $v;
    }
}
