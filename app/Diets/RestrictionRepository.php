<?php
declare(strict_types=1);

namespace App\Diets;

use App\Audit\AuditLog;
use App\Core\Database;
use App\Core\Request;

/** Restricoes e alergias alimentares do paciente (uma linha por paciente; alteracoes auditadas). */
final class RestrictionRepository
{
    public function __construct(private Database $db, private AuditLog $audit)
    {
    }

    /** @return array{items:list<string>,other_text:string,updated_at:?string,updated_by_name:?string} */
    public function get(int $patientId): array
    {
        $row = $this->db->one(
            'SELECT r.*, u.name AS updated_by_name FROM patient_food_restrictions r JOIN users u ON u.id = r.updated_by WHERE r.patient_id = ?',
            [$patientId],
        );
        return [
            'items' => $row === null || $row['items'] === '' ? [] : explode(',', (string) $row['items']),
            'other_text' => (string) ($row['other_text'] ?? ''),
            'updated_at' => $row['updated_at'] ?? null,
            'updated_by_name' => $row['updated_by_name'] ?? null,
        ];
    }

    /** @param list<string> $items */
    public function save(int $patientId, array $items, string $other, int $by, ?Request $r = null): void
    {
        $items = array_values(array_intersect(array_keys(DietAdapter::RESTRICTIONS), $items));
        $other = trim(mb_substr(mb_scrub($other, 'UTF-8'), 0, 500, 'UTF-8'));
        $this->db->transaction(function () use ($patientId, $items, $other, $by, $r): void {
            $before = $this->get($patientId);
            $this->db->run(
                'INSERT INTO patient_food_restrictions (patient_id, items, other_text, updated_by, updated_at) VALUES (?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE items = VALUES(items), other_text = VALUES(other_text), updated_by = VALUES(updated_by), updated_at = NOW()',
                [$patientId, implode(',', $items), $other === '' ? null : $other, $by],
            );
            $this->audit->record($by, 'update', 'patient_food_restrictions', $patientId, $patientId,
                ['items' => $before['items'], 'other_text' => $before['other_text']], ['items' => $items, 'other_text' => $other], $r);
        });
    }
}
