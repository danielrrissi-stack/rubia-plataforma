<?php
declare(strict_types=1);

namespace App\Audit;

use App\Core\Database;
use App\Core\Request;

/** Trilha de auditoria append-only: apenas INSERT e leitura. */
final class AuditLog
{
    public function __construct(private Database $db)
    {
    }

    public function record(
        ?int $userId,
        string $action,
        string $entity,
        ?int $entityId,
        ?int $patientId = null,
        ?array $before = null,
        ?array $after = null,
        ?Request $req = null
    ): void {
        $this->db->insert('audit_log', [
            'user_id' => $userId,
            'action' => $action,
            'entity' => $entity,
            'entity_id' => $entityId,
            'patient_id' => $patientId,
            'before_json' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
            'after_json' => $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE),
            'ip' => $req ? mb_substr($req->ip(), 0, 45) : '',
            'user_agent' => $req ? mb_substr($req->userAgent(), 0, 255) : '',
        ]);
    }

    /** Eventos do paciente, mais recentes primeiro. */
    public function forPatient(int $patientId, int $limit = 200): array
    {
        return $this->db->all(
            'SELECT * FROM audit_log WHERE patient_id = ? ORDER BY created_at DESC, id DESC LIMIT ' . max(1, $limit),
            [$patientId]
        );
    }
}
