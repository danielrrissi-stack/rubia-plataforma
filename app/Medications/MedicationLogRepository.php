<?php
declare(strict_types=1);

namespace App\Medications;

use App\Audit\AuditLog;
use App\Core\Database;
use App\Core\Request;

/** Doses registradas pela familia (paciente/responsavel). A visibilidade vem sempre de PrescriptionRepository::visibleTo(). */
final class MedicationLogRepository
{
    public function __construct(private Database $db, private AuditLog $audit, private PrescriptionRepository $prescriptions)
    {
    }

    /** @param string $givenAt 'Y-m-d H:i'; $now idem */
    public function record(int $prescriptionId, string $givenAt, ?string $note, array $user, string $now, Request $r): void
    {
        $role = (string) $user['role'];
        if (!in_array($role, ['paciente', 'responsavel'], true)) {
            throw new InvalidMedicationException(['Apenas a família registra doses.']);
        }
        $p = $this->prescriptions->visibleTo($prescriptionId, $user);
        if ($p === null) {
            throw new InvalidMedicationException(['Medicamento não encontrado.']);
        }
        if ((int) $p['family_logs'] !== 1) {
            throw new InvalidMedicationException(['O registro de doses pela família não está ativado para este medicamento.']);
        }
        $at = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $givenAt);
        if ($at === false || $at->format('Y-m-d H:i') !== $givenAt) {
            throw new InvalidMedicationException(['Informe a data e a hora da dose.']);
        }
        $nowAt = new \DateTimeImmutable($now);
        if ($at > $nowAt) {
            throw new InvalidMedicationException(['A dose não pode estar no futuro.']);
        }
        if ($at < $nowAt->modify('-7 days')) {
            throw new InvalidMedicationException(['Só é possível registrar doses dos últimos 7 dias.']);
        }
        $day = $at->format('Y-m-d');
        if ($day < $p['start_date'] || ($p['end_date'] !== null && $day > $p['end_date'])) {
            throw new InvalidMedicationException(['A data da dose está fora do período do medicamento.']);
        }
        $note = trim((string) $note);
        if (mb_strlen($note) > 200) {
            throw new InvalidMedicationException(['A observação deve ter até 200 caracteres.']);
        }
        $uid = (int) $user['id'];
        $dup = $this->db->one(
            'SELECT id FROM medication_logs WHERE prescription_id = ? AND recorded_by = ? AND given_at = ? AND deleted_at IS NULL',
            [$prescriptionId, $uid, $givenAt . ':00'],
        );
        if ($dup !== null) {
            return;
        }
        $dose = $this->prescriptions->doseOn($prescriptionId, $day);
        $row = [
            'prescription_id' => $prescriptionId, 'given_at' => $givenAt . ':00', 'dose' => $dose['dose'] ?? '',
            'note' => $note === '' ? null : $note, 'recorded_by' => $uid, 'recorded_by_role' => $role,
        ];
        $id = $this->db->insert('medication_logs', $row);
        $this->audit->record($uid, 'create', 'medication_logs', $id, (int) $p['patient_id'], null, $row, $r);
    }

    public function remove(int $logId, array $user, string $now, Request $r): void
    {
        $log = $this->db->one('SELECT * FROM medication_logs WHERE id = ?', [$logId]);
        $uid = (int) $user['id'];
        $min = (new \DateTimeImmutable($now))->modify('-7 days')->format('Y-m-d H:i:s');
        $p = $log === null ? null : $this->prescriptions->visibleTo((int) $log['prescription_id'], $user);
        if ($log === null || $p === null || $log['deleted_at'] !== null || (int) $log['recorded_by'] !== $uid || $log['given_at'] < $min) {
            throw new InvalidMedicationException(['Registro não encontrado.']);
        }
        $this->db->run('UPDATE medication_logs SET deleted_at = NOW(), deleted_by = ? WHERE id = ?', [$uid, $logId]);
        $this->audit->record($uid, 'delete', 'medication_logs', $logId, (int) $p['patient_id'], $log, $this->db->one('SELECT * FROM medication_logs WHERE id = ?', [$logId]), $r);
    }

    /** @return list<array> */
    public function forPrescription(int $prescriptionId, array $viewer, string $since): array
    {
        if ($this->prescriptions->visibleTo($prescriptionId, $viewer) === null) {
            return [];
        }
        return $this->db->all(
            'SELECT l.*, u.name AS recorder_name FROM medication_logs l JOIN users u ON u.id = l.recorded_by
             WHERE l.prescription_id = ? AND l.deleted_at IS NULL AND l.given_at >= ?
             ORDER BY l.given_at DESC, l.id DESC',
            [$prescriptionId, $since],
        );
    }
}
