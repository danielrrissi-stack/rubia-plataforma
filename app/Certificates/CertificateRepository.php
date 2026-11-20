<?php
declare(strict_types=1);

namespace App\Certificates;

use App\Core\Database;

/** Atestados emitidos: nada e apagado; um emitido por engano e anulado com motivo. */
final class CertificateRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @param array<string,mixed> $payload campos digitados */
    public function create(int $patientId, int $authorId, string $type, string $issuedOn, array $payload, string $bodyHtml, ?string $cid): int
    {
        return $this->db->insert('certificates', [
            'patient_id' => $patientId, 'author_id' => $authorId, 'type' => $type, 'issued_on' => $issuedOn,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'body_html' => $bodyHtml, 'cid' => $cid,
        ]);
    }

    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM certificates WHERE id = ?', [$id]);
    }

    /** @return list<array> mais recente primeiro */
    public function forPatient(int $patientId, ?int $limit = null): array
    {
        $sql = 'SELECT * FROM certificates WHERE patient_id = ? ORDER BY issued_on DESC, id DESC';
        if ($limit !== null) {
            $sql .= ' LIMIT ' . max(1, $limit);
        }
        return $this->db->all($sql, [$patientId]);
    }

    public function void(int $id, int $userId, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 200) {
            throw new InvalidCertificateException(['Informe o motivo da anulação (até 200 caracteres).']);
        }
        $done = $this->db->run(
            'UPDATE certificates SET voided_at = NOW(), voided_by = ?, void_reason = ? WHERE id = ? AND voided_at IS NULL',
            [$userId, $reason, $id],
        )->rowCount();
        if ($done !== 1) {
            throw new InvalidCertificateException(['Este atestado já foi anulado.']);
        }
    }
}
