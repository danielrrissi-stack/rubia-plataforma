<?php
declare(strict_types=1);

namespace App\Patients;

use App\Audit\AuditLog;
use App\Core\Database;
use App\Core\Request;

/** Liberacao de pacientes para nutricionistas (professional_access). Autorizacao e feita no controller. */
final class AccessGrantRepository
{
    public function __construct(private Database $db, private AuditLog $audit)
    {
    }

    /** Idempotente: se ja ha liberacao ativa, devolve o id existente. */
    public function grant(int $patientId, int $professionalId, int $by, ?Request $r = null): int
    {
        return $this->db->transaction(function () use ($patientId, $professionalId, $by, $r): int {
            $existing = $this->db->one(
                'SELECT id FROM professional_access WHERE patient_id = ? AND professional_id = ? AND revoked_at IS NULL LIMIT 1 FOR UPDATE',
                [$patientId, $professionalId]
            );
            if ($existing !== null) {
                return (int) $existing['id'];
            }
            $id = $this->db->insert('professional_access', [
                'patient_id' => $patientId, 'professional_id' => $professionalId, 'granted_by' => $by,
            ]);
            $this->audit->record($by, 'grant', 'professional_access', $id, $patientId, null,
                ['professional_id' => $professionalId], $r);
            return $id;
        });
    }

    public function revoke(int $grantId, int $by, ?Request $r = null): void
    {
        $grant = $this->find($grantId);
        if ($grant === null || $grant['revoked_at'] !== null) {
            return;
        }
        $this->db->run('UPDATE professional_access SET revoked_at = NOW(), revoked_by = ? WHERE id = ?', [$by, $grantId]);
        $this->audit->record($by, 'revoke', 'professional_access', $grantId, (int) $grant['patient_id'],
            ['professional_id' => (int) $grant['professional_id']], null, $r);
    }

    public function find(int $grantId): ?array
    {
        return $this->db->one('SELECT * FROM professional_access WHERE id = ?', [$grantId]);
    }

    public function activeFor(int $patientId): array
    {
        return $this->db->all(
            'SELECT a.*, u.name AS professional_name, u.email AS professional_email
             FROM professional_access a JOIN users u ON u.id = a.professional_id
             WHERE a.patient_id = ? AND a.revoked_at IS NULL ORDER BY a.id',
            [$patientId]
        );
    }

    /** Nutricionistas ativos ainda sem liberacao ativa para o paciente. */
    public function available(int $patientId): array
    {
        return $this->db->all(
            "SELECT id, name FROM users WHERE role = 'nutricionista' AND active = 1
             AND id NOT IN (SELECT professional_id FROM professional_access WHERE patient_id = ? AND revoked_at IS NULL)
             ORDER BY name",
            [$patientId]
        );
    }
}
