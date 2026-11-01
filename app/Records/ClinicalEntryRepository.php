<?php
declare(strict_types=1);

namespace App\Records;

use App\Audit\AuditLog;
use App\Core\Database;
use App\Core\Request;

/** Prontuario versionado: o payload nunca e alterado; correcoes criam nova versao. */
final class ClinicalEntryRepository
{
    public const HISTORY_KINDS = [
        'historico_familiar', 'gestacional', 'antecedentes', 'alergias', 'comorbidades', 'medicamentos_em_uso', 'tratamentos_anteriores',
    ];

    public function __construct(private Database $db, private AuditLog $audit)
    {
    }

    public function add(int $patientId, string $kind, array $payload, ?string $cid10, ?string $encounterDate, int $by, ?Request $r = null): int
    {
        if (!in_array($kind, [...self::HISTORY_KINDS, 'evolucao'], true)) {
            throw new \InvalidArgumentException('Tipo de registro inválido.');
        }
        return $this->db->transaction(function () use ($patientId, $kind, $payload, $cid10, $encounterDate, $by, $r): int {
            $row = [
                'patient_id' => $patientId,
                'kind' => $kind,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'cid10' => $cid10 === '' ? null : $cid10,
                'encounter_date' => $encounterDate === '' ? null : $encounterDate,
                'version' => 1,
                'created_by' => $by,
            ];
            $id = $this->db->insert('clinical_entries', $row);
            $this->audit->record($by, 'create', 'clinical_entries', $id, $patientId, null, self::snapshot($row), $r);
            return $id;
        });
    }

    /** Cria a versao seguinte; a anterior fica substituida. $encounterDate null = mantem a data anterior. */
    public function revise(int $id, array $payload, ?string $cid10, int $by, ?Request $r = null, ?string $encounterDate = null): int
    {
        return $this->db->transaction(function () use ($id, $payload, $cid10, $by, $r, $encounterDate): int {
            $old = $this->db->one('SELECT * FROM clinical_entries WHERE id = ? FOR UPDATE', [$id]);
            if ($old === null || $old['superseded_at'] !== null || $old['deleted_at'] !== null) {
                throw new \InvalidArgumentException('Este registro não pode mais ser corrigido (já foi substituído ou removido).');
            }
            $row = [
                'patient_id' => (int) $old['patient_id'],
                'kind' => $old['kind'],
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'cid10' => $cid10 === '' ? null : $cid10,
                'encounter_date' => $encounterDate ?? $old['encounter_date'],
                'version' => (int) $old['version'] + 1,
                'previous_id' => $id,
                'created_by' => $by,
            ];
            $new = $this->db->insert('clinical_entries', $row);
            $this->db->run('UPDATE clinical_entries SET superseded_at = NOW() WHERE id = ?', [$id]);
            $this->audit->record($by, 'update', 'clinical_entries', $new, (int) $old['patient_id'], self::snapshot($old), self::snapshot($row), $r);
            return $new;
        });
    }

    public function remove(int $id, string $reason, int $by, ?Request $r = null): void
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 255) {
            throw new \InvalidArgumentException('Informe o motivo da remoção (até 255 caracteres).');
        }
        $this->db->transaction(function () use ($id, $reason, $by, $r): void {
            $old = $this->db->one('SELECT * FROM clinical_entries WHERE id = ? FOR UPDATE', [$id]);
            if ($old === null || $old['superseded_at'] !== null || $old['deleted_at'] !== null) {
                throw new \InvalidArgumentException('Este registro não pode mais ser removido.');
            }
            $this->db->run('UPDATE clinical_entries SET deleted_at = NOW(), deleted_reason = ? WHERE id = ?', [$reason, $id]);
            $this->audit->record($by, 'delete', 'clinical_entries', $id, (int) $old['patient_id'], self::snapshot($old), ['deleted_reason' => $reason], $r);
        });
    }

    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM clinical_entries WHERE id = ?', [$id]);
    }

    /** Versoes vigentes de um tipo de historico, com autor. */
    public function currentByKind(int $patientId, string $kind): array
    {
        return $this->db->all(
            'SELECT e.*, u.name AS author_name, u.professional_register AS author_register
             FROM clinical_entries e JOIN users u ON u.id = e.created_by
             WHERE e.patient_id = ? AND e.kind = ? AND e.superseded_at IS NULL AND e.deleted_at IS NULL ORDER BY e.id DESC',
            [$patientId, $kind],
        );
    }

    /** Evolucoes vigentes, mais recentes primeiro. */
    public function evolutions(int $patientId): array
    {
        return $this->db->all(
            "SELECT e.*, u.name AS author_name, u.professional_register AS author_register
             FROM clinical_entries e JOIN users u ON u.id = e.created_by
             WHERE e.patient_id = ? AND e.kind = 'evolucao' AND e.superseded_at IS NULL AND e.deleted_at IS NULL
             ORDER BY e.encounter_date DESC, e.id DESC",
            [$patientId],
        );
    }

    /** Cadeia completa de versoes (da primeira a ultima) a que $id pertence, com autor. */
    public function versions(int $id): array
    {
        $row = $this->find($id);
        if ($row === null) {
            return [];
        }
        while ($row['previous_id'] !== null) {
            $prev = $this->find((int) $row['previous_id']);
            if ($prev === null) {
                break;
            }
            $row = $prev;
        }
        $chain = [$row['id']];
        $cur = $row['id'];
        while (($next = $this->db->one('SELECT id FROM clinical_entries WHERE previous_id = ? ORDER BY id LIMIT 1', [$cur])) !== null) {
            $chain[] = $cur = $next['id'];
        }
        $in = implode(',', array_fill(0, count($chain), '?'));
        return $this->db->all(
            "SELECT e.*, u.name AS author_name, u.professional_register AS author_register
             FROM clinical_entries e JOIN users u ON u.id = e.created_by WHERE e.id IN ($in) ORDER BY e.version, e.id",
            array_map('intval', $chain),
        );
    }

    private static function snapshot(array $row): array
    {
        $payload = $row['payload'];
        return [
            'kind' => $row['kind'],
            'payload' => is_string($payload) ? json_decode($payload, true) : $payload,
            'cid10' => $row['cid10'] ?? null,
            'encounter_date' => $row['encounter_date'] ?? null,
            'version' => (int) $row['version'],
        ];
    }
}
