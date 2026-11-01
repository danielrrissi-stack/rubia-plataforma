<?php
declare(strict_types=1);

namespace App\Patients;

use App\Audit\AuditLog;
use App\Core\Database;
use App\Core\Request;

final class GuardianRepository
{
    public function __construct(private Database $db, private AuditLog $audit)
    {
    }

    public function link(int $patientId, int $userId, string $relationship, int $by, ?Request $r = null): int
    {
        $id = $this->db->insert('guardian_links', [
            'patient_id' => $patientId,
            'user_id' => $userId,
            'relationship' => $relationship,
            'created_by' => $by,
        ]);
        $this->audit->record($by, 'link', 'guardian_links', $id, $patientId, null, [
            'user_id' => $userId, 'relationship' => $relationship,
        ], $r);
        return $id;
    }

    public function unlink(int $linkId, int $by, ?Request $r = null): void
    {
        $link = $this->find($linkId);
        if ($link === null || (int) $link['active'] !== 1) {
            return;
        }
        $this->db->run(
            'UPDATE guardian_links SET active = 0, ended_at = NOW(), ended_by = ? WHERE id = ?',
            [$by, $linkId]
        );
        $this->audit->record($by, 'unlink', 'guardian_links', $linkId, (int) $link['patient_id'], [
            'user_id' => (int) $link['user_id'], 'relationship' => $link['relationship'], 'active' => 1,
        ], ['active' => 0], $r);
    }

    public function find(int $linkId): ?array
    {
        return $this->db->one('SELECT * FROM guardian_links WHERE id = ?', [$linkId]);
    }

    public function activeLink(int $patientId, int $userId): ?array
    {
        return $this->db->one(
            'SELECT * FROM guardian_links WHERE patient_id = ? AND user_id = ? AND active = 1',
            [$patientId, $userId]
        );
    }

    /** Vinculos ativos com dados do responsavel. */
    public function forPatient(int $pid): array
    {
        return $this->db->all(
            'SELECT g.*, u.name AS user_name, u.email AS user_email, (u.password_hash IS NOT NULL) AS has_password
             FROM guardian_links g JOIN users u ON u.id = g.user_id
             WHERE g.patient_id = ? AND g.active = 1 ORDER BY g.id',
            [$pid]
        );
    }

    public function patientsForGuardian(int $uid): array
    {
        return $this->db->all(
            'SELECT p.*, g.relationship FROM guardian_links g JOIN patients p ON p.id = g.patient_id
             WHERE g.user_id = ? AND g.active = 1 AND p.deleted_at IS NULL ORDER BY p.full_name',
            [$uid]
        );
    }
}
