<?php
declare(strict_types=1);

namespace App\Medications;

use App\Audit\AuditLog;
use App\Core\Database;
use App\Core\Request;

/** Relatos de efeitos adversos (familia ou equipe). */
final class AdverseEventRepository
{
    private const SEVERITIES = ['leve', 'moderada', 'grave'];

    public function __construct(private Database $db, private AuditLog $audit, private PrescriptionRepository $prescriptions)
    {
    }

    public function report(int $patientId, array $data, array $user, string $today, Request $r): int
    {
        $role = (string) $user['role'];
        if (!in_array($role, ['paciente', 'responsavel', 'medico'], true)) {
            throw new InvalidMedicationException(['Sem permissão para relatar efeito adverso.']);
        }
        $doctor = $role === 'medico';
        $errors = [];
        $on = (string) ($data['occurred_on'] ?? '');
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $on);
        if ($d === false || $d->format('Y-m-d') !== $on) {
            $errors[] = 'Informe a data em que ocorreu.';
        } elseif ($on > $today) {
            $errors[] = 'A data não pode ser futura.';
        } elseif (!$doctor && $on < (new \DateTimeImmutable($today))->modify('-90 days')->format('Y-m-d')) {
            $errors[] = 'A data deve estar nos últimos 90 dias.';
        }
        $desc = trim((string) ($data['description'] ?? ''));
        if ($desc === '') {
            $errors[] = 'Descreva o que aconteceu.';
        } elseif (mb_strlen($desc) > 2000) {
            $errors[] = 'A descrição deve ter até 2000 caracteres.';
        }
        $rxId = null;
        $rawRx = (int) ($data['prescription_id'] ?? 0);
        if ($rawRx !== 0) {
            $p = $this->prescriptions->visibleTo($rawRx, $user);
            if ($p === null || (int) $p['patient_id'] !== $patientId) {
                $errors[] = 'Medicamento não encontrado.';
            } else {
                $rxId = (int) $p['id'];
            }
        }
        $severity = null;
        $conduct = null;
        if ($doctor) {
            $severity = $this->severity($data['severity'] ?? '', $errors);
            $conduct = $this->conduct($data['conduct'] ?? '', $errors);
        }
        if ($errors !== []) {
            throw new InvalidMedicationException($errors);
        }
        $uid = (int) $user['id'];
        $row = [
            'patient_id' => $patientId, 'prescription_id' => $rxId, 'occurred_on' => $on, 'description' => $desc,
            'source' => $doctor ? 'equipe' : 'familia', 'severity' => $severity, 'conduct' => $conduct,
            'reported_by' => $uid, 'reported_by_role' => $role,
        ];
        if ($doctor) {
            $row['reviewed_by'] = $uid;
            $row['reviewed_at'] = date('Y-m-d H:i:s');
        }
        $id = $this->db->insert('adverse_events', $row);
        $this->audit->record($uid, 'create', 'adverse_events', $id, $patientId, null, $row, $r);
        return $id;
    }

    public function review(int $eventId, ?string $severity, ?string $conduct, int $uid, Request $r): void
    {
        $before = $this->db->one('SELECT * FROM adverse_events WHERE id = ?', [$eventId]);
        if ($before === null || $before['deleted_at'] !== null || $before['source'] !== 'familia' || $before['reviewed_by'] !== null) {
            throw new InvalidMedicationException(['Relato não encontrado ou já revisado.']);
        }
        $errors = [];
        $sev = $this->severity($severity ?? '', $errors);
        $con = $this->conduct($conduct ?? '', $errors);
        if ($errors !== []) {
            throw new InvalidMedicationException($errors);
        }
        $this->db->run(
            'UPDATE adverse_events SET severity = ?, conduct = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?',
            [$sev, $con, $uid, $eventId],
        );
        $this->audit->record($uid, 'review', 'adverse_events', $eventId, (int) $before['patient_id'], $before, $this->db->one('SELECT * FROM adverse_events WHERE id = ?', [$eventId]), $r);
    }

    /** @return list<array> */
    public function forViewer(int $patientId, array $viewer): array
    {
        $where = match ((string) $viewer['role']) {
            'medico' => '',
            'paciente' => " AND e.source = 'familia'",
            'responsavel' => " AND e.source = 'familia' AND (e.prescription_id IS NULL OR p.secret = 0)",
            default => null,
        };
        if ($where === null) {
            return [];
        }
        return $this->db->all(
            'SELECT e.*, p.drug_name, u.name AS reporter_name FROM adverse_events e
             LEFT JOIN prescriptions p ON p.id = e.prescription_id
             JOIN users u ON u.id = e.reported_by
             WHERE e.patient_id = ? AND e.deleted_at IS NULL' . $where . '
             ORDER BY e.occurred_on DESC, e.id DESC',
            [$patientId],
        );
    }

    /**
     * @param list<int>|null $patientIds null = todos
     * @return list<array>
     */
    public function unreviewed(?array $patientIds): array
    {
        $params = [];
        $in = '';
        if ($patientIds !== null) {
            if ($patientIds === []) {
                return [];
            }
            $in = ' AND e.patient_id IN (' . implode(',', array_fill(0, count($patientIds), '?')) . ')';
            $params = array_map('intval', array_values($patientIds));
        }
        return $this->db->all(
            "SELECT e.*, COALESCE(NULLIF(pt.social_name, ''), pt.full_name) AS patient_name, p.drug_name FROM adverse_events e
             JOIN patients pt ON pt.id = e.patient_id AND pt.deleted_at IS NULL
             LEFT JOIN prescriptions p ON p.id = e.prescription_id
             WHERE e.source = 'familia' AND e.reviewed_by IS NULL AND e.deleted_at IS NULL" . $in . '
             ORDER BY e.created_at, e.id',
            $params,
        );
    }

    /** @param list<string> $errors */
    private function severity(mixed $v, array &$errors): ?string
    {
        $s = trim((string) $v);
        if ($s === '') {
            return null;
        }
        if (!in_array($s, self::SEVERITIES, true)) {
            $errors[] = 'Gravidade inválida.';
            return null;
        }
        return $s;
    }

    /** @param list<string> $errors */
    private function conduct(mixed $v, array &$errors): ?string
    {
        $s = trim((string) $v);
        if (mb_strlen($s) > 2000) {
            $errors[] = 'A conduta deve ter até 2000 caracteres.';
        }
        return $s === '' ? null : $s;
    }
}
