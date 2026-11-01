<?php
declare(strict_types=1);

namespace App\Energy;

use App\Audit\AuditLog;
use App\Core\Database;
use App\Core\Request;

final class EnergyGoalRepository
{
    public function __construct(private Database $db, private AuditLog $audit)
    {
    }

    /** Salva a nova meta e marca a anterior como substituida. Espera patient_id, formula, activity_factor, weight_kg, height_cm, age_years, tmb_kcal, get_kcal, notes. */
    public function save(array $goal, int $by, ?Request $r = null): int
    {
        return $this->db->transaction(function () use ($goal, $by, $r): int {
            $pid = (int) $goal['patient_id'];
            $this->db->run('UPDATE energy_goals SET superseded_at = NOW() WHERE patient_id = ? AND superseded_at IS NULL', [$pid]);
            $row = [
                'patient_id' => $pid,
                'formula' => $goal['formula'],
                'activity_factor' => $goal['activity_factor'],
                'weight_kg' => $goal['weight_kg'],
                'height_cm' => $goal['height_cm'],
                'age_years' => $goal['age_years'],
                'tmb_kcal' => (int) $goal['tmb_kcal'],
                'get_kcal' => (int) $goal['get_kcal'],
                'notes' => ($goal['notes'] ?? '') === '' ? null : $goal['notes'],
                'created_by' => $by,
            ];
            $id = $this->db->insert('energy_goals', $row);
            $this->audit->record($by, 'create', 'energy_goals', $id, $pid, null, $row, $r);
            return $id;
        });
    }

    public function current(int $patientId): ?array
    {
        return $this->db->one(
            'SELECT g.*, u.name AS professional FROM energy_goals g JOIN users u ON u.id = g.created_by
             WHERE g.patient_id = ? AND g.superseded_at IS NULL ORDER BY g.id DESC LIMIT 1',
            [$patientId],
        );
    }

    public function history(int $patientId): array
    {
        return $this->db->all(
            'SELECT g.*, u.name AS professional FROM energy_goals g JOIN users u ON u.id = g.created_by
             WHERE g.patient_id = ? ORDER BY g.id DESC',
            [$patientId],
        );
    }
}
