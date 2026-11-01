<?php
declare(strict_types=1);

namespace App\Access;

use App\Core\Database;

/** Quais pacientes um usuario pode alcancar. Pacientes com deleted_at ficam sempre fora. */
final class PatientScope
{
    public function __construct(private Database $db)
    {
    }

    public function canAccess(array $user, int $patientId): bool
    {
        if ((int) ($user['active'] ?? 0) !== 1 || !isset($user['id'])) {
            return false;
        }
        $uid = (int) $user['id'];
        switch ((string) ($user['role'] ?? '')) {
            case 'medico':
            case 'secretaria':
                $sql = 'SELECT 1 FROM patients p WHERE p.id = ? AND p.deleted_at IS NULL';
                $params = [$patientId];
                break;
            case 'nutricionista':
                $sql = 'SELECT 1 FROM patients p JOIN professional_access a ON a.patient_id = p.id
                        WHERE p.id = ? AND p.deleted_at IS NULL AND a.professional_id = ? AND a.revoked_at IS NULL';
                $params = [$patientId, $uid];
                break;
            case 'paciente':
                $sql = 'SELECT 1 FROM patients p WHERE p.id = ? AND p.deleted_at IS NULL AND p.user_id = ?';
                $params = [$patientId, $uid];
                break;
            case 'responsavel':
                $sql = 'SELECT 1 FROM patients p JOIN guardian_links g ON g.patient_id = p.id
                        WHERE p.id = ? AND p.deleted_at IS NULL AND g.user_id = ? AND g.active = 1';
                $params = [$patientId, $uid];
                break;
            default:
                return false;
        }
        return $this->db->one($sql . ' LIMIT 1', $params) !== null;
    }

    /** @return int[]|null null = todos os pacientes (nao excluidos) */
    public function accessiblePatientIds(array $user): ?array
    {
        if ((int) ($user['active'] ?? 0) !== 1 || !isset($user['id'])) {
            return [];
        }
        $uid = (int) $user['id'];
        switch ((string) ($user['role'] ?? '')) {
            case 'medico':
            case 'secretaria':
                return null;
            case 'nutricionista':
                $sql = 'SELECT DISTINCT p.id FROM patients p JOIN professional_access a ON a.patient_id = p.id
                        WHERE p.deleted_at IS NULL AND a.professional_id = ? AND a.revoked_at IS NULL ORDER BY p.id';
                break;
            case 'paciente':
                $sql = 'SELECT p.id FROM patients p WHERE p.deleted_at IS NULL AND p.user_id = ? ORDER BY p.id';
                break;
            case 'responsavel':
                $sql = 'SELECT DISTINCT p.id FROM patients p JOIN guardian_links g ON g.patient_id = p.id
                        WHERE p.deleted_at IS NULL AND g.user_id = ? AND g.active = 1 ORDER BY p.id';
                break;
            default:
                return [];
        }
        return array_map(static fn(array $r): int => (int) $r['id'], $this->db->all($sql, [$uid]));
    }
}
