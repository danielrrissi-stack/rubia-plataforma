<?php
declare(strict_types=1);

namespace App\Nutrition;

use App\Audit\AuditLog;
use App\Core\Database;
use App\Core\Request;

/** Modelos de plano alimentar compartilhados pela equipe (texto ou estruturado). Exclusao logica. */
final class TemplateRepository
{
    public function __construct(private Database $db, private AuditLog $audit)
    {
    }

    /** @param array{title:string,mode:string,body_text?:?string,guidance_text?:?string,structure?:mixed} $t */
    public function create(array $t, int $by, ?Request $r = null): int
    {
        $row = $this->clean($t);
        return $this->db->transaction(function () use ($row, $by, $r): int {
            $id = $this->db->insert('meal_plan_templates', $row + ['created_by' => $by]);
            $this->audit->record($by, 'create', 'meal_plan_templates', $id, null, null, $this->auditView($row), $r);
            return $id;
        });
    }

    public function fromPlan(int $planId, string $title, int $by, ?Request $r = null): int
    {
        $plan = $this->db->one('SELECT * FROM meal_plans WHERE id = ?', [$planId]);
        if ($plan === null) {
            throw new \RuntimeException('Plano não encontrado.');
        }
        $structure = $plan['structure_json'] === null ? null : json_decode((string) $plan['structure_json'], true);
        return $this->create([
            'title' => $title, 'mode' => (string) $plan['mode'],
            'body_text' => $plan['body_text'], 'guidance_text' => $plan['guidance_text'],
            'structure' => is_array($structure) ? $structure : null,
        ], $by, $r);
    }

    /**
     * $auto = salvamento automatico do editor estruturado (a cada 30 s): auditado no maximo a cada 10 minutos por
     * pessoa e modelo, como os rascunhos de plano. Salvamento explicito e sempre auditado.
     */
    public function update(int $id, array $t, array $user, ?Request $r = null, bool $auto = false): void
    {
        $before = $this->mustFind($id);
        $this->assertOwner($before, $user);
        $row = $this->clean($t);
        $this->db->transaction(function () use ($id, $row, $before, $user, $r, $auto): void {
            $this->db->run(
                'UPDATE meal_plan_templates SET title = ?, mode = ?, body_text = ?, guidance_text = ?, structure_json = ?, updated_at = NOW() WHERE id = ?',
                [$row['title'], $row['mode'], $row['body_text'], $row['guidance_text'], $row['structure_json'], $id],
            );
            if ($auto) {
                // Indice (user_id, created_at): consulta curta mesmo com auditoria grande.
                $recent = $this->db->one(
                    "SELECT id FROM audit_log WHERE user_id = ? AND created_at >= NOW() - INTERVAL 10 MINUTE
                       AND entity = 'meal_plan_templates' AND entity_id = ? AND action = 'update' LIMIT 1",
                    [(int) $user['id'], $id],
                );
                if ($recent !== null) {
                    return;
                }
            }
            $this->audit->record((int) $user['id'], 'update', 'meal_plan_templates', $id, null, $this->auditView($before), $this->auditView($row) + ['auto' => $auto], $r);
        });
    }

    public function delete(int $id, array $user, ?Request $r = null): void
    {
        $before = $this->mustFind($id);
        $this->assertOwner($before, $user);
        $this->db->transaction(function () use ($id, $before, $user, $r): void {
            $this->db->run('UPDATE meal_plan_templates SET deleted_at = NOW() WHERE id = ?', [$id]);
            $this->audit->record((int) $user['id'], 'delete', 'meal_plan_templates', $id, null, $this->auditView($before), null, $r);
        });
    }

    /** Nao excluido; `structure` ja decodificado. */
    public function find(int $id): ?array
    {
        $row = $this->db->one('SELECT * FROM meal_plan_templates WHERE id = ? AND deleted_at IS NULL', [$id]);
        if ($row === null) {
            return null;
        }
        $row['structure'] = $row['structure_json'] === null ? null : json_decode((string) $row['structure_json'], true);
        return $row;
    }

    /** @return list<array> nao excluidos, por titulo, com o nome do autor */
    public function all(): array
    {
        return $this->db->all(
            'SELECT t.id, t.title, t.mode, t.created_by, t.created_at, t.updated_at, u.name AS author
             FROM meal_plan_templates t JOIN users u ON u.id = t.created_by
             WHERE t.deleted_at IS NULL ORDER BY t.title, t.id',
        );
    }

    public static function canModify(array $row, array $user): bool
    {
        return (int) $row['created_by'] === (int) ($user['id'] ?? 0) || (int) ($user['is_admin'] ?? 0) === 1;
    }

    private function assertOwner(array $row, array $user): void
    {
        if (!self::canModify($row, $user)) {
            throw new \RuntimeException('Somente quem criou o modelo ou a administradora pode alterá-lo.');
        }
    }

    private function mustFind(int $id): array
    {
        $row = $this->find($id);
        if ($row === null) {
            throw new \RuntimeException('Modelo não encontrado.');
        }
        return $row;
    }

    private function clean(array $t): array
    {
        $title = trim(mb_scrub((string) ($t['title'] ?? ''), 'UTF-8'));
        $len = mb_strlen($title, 'UTF-8');
        if ($len < 2 || $len > 150) {
            throw new \RuntimeException('Informe o título do modelo (2 a 150 caracteres).');
        }
        $mode = (string) ($t['mode'] ?? '');
        if (!in_array($mode, ['texto', 'estruturado'], true)) {
            throw new \RuntimeException('Modo do modelo inválido.');
        }
        $text = static fn(mixed $v): ?string => $v === null || trim((string) $v) === '' ? null : (string) $v;
        $structure = $mode === 'estruturado' ? ($t['structure'] ?? null) : null;
        return [
            'title' => $title, 'mode' => $mode,
            'body_text' => $mode === 'texto' ? $text($t['body_text'] ?? null) : null,
            'guidance_text' => $text($t['guidance_text'] ?? null),
            'structure_json' => $structure === null ? null : json_encode($structure, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ];
    }

    private function auditView(array $row): array
    {
        return [
            'title' => $row['title'], 'mode' => $row['mode'],
            'body_text' => $row['body_text'], 'guidance_text' => $row['guidance_text'],
            'structure' => $row['structure_json'] === null ? null : json_decode((string) $row['structure_json'], true),
        ];
    }
}
