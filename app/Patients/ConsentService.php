<?php
declare(strict_types=1);

namespace App\Patients;

use App\Audit\AuditLog;
use App\Core\Database;
use App\Core\Request;

/** Termo LGPD: pendencias por usuario de familia (um por paciente vinculado para responsavel). */
final class ConsentService
{
    public function __construct(private Database $db, private AuditLog $audit)
    {
    }

    /**
     * @return array<int,array{term_version:string,body_html:string,patient_id:?int,patient_name:?string}>
     */
    public function pendingFor(array $user): array
    {
        $role = (string) ($user['role'] ?? '');
        $uid = (int) ($user['id'] ?? 0);
        if (!in_array($role, ['paciente', 'responsavel'], true) || $uid === 0) {
            return [];
        }
        $term = $this->db->one('SELECT version, body_html FROM consent_terms WHERE active = 1 ORDER BY id DESC LIMIT 1');
        if ($term === null) {
            return [];
        }
        if ($role === 'responsavel') {
            $patients = $this->db->all(
                'SELECT DISTINCT p.id, p.full_name FROM patients p JOIN guardian_links g ON g.patient_id = p.id
                 WHERE g.user_id = ? AND g.active = 1 AND p.deleted_at IS NULL ORDER BY p.id',
                [$uid]
            );
        } else {
            $patients = $this->db->all(
                'SELECT id, full_name FROM patients WHERE user_id = ? AND deleted_at IS NULL ORDER BY id',
                [$uid]
            );
        }
        $accepted = [];
        foreach ($this->db->all('SELECT patient_id FROM consents WHERE user_id = ? AND term_version = ?', [$uid, $term['version']]) as $c) {
            $accepted[(int) $c['patient_id']] = true;
        }
        $out = [];
        foreach ($patients as $p) {
            if (!isset($accepted[(int) $p['id']])) {
                $out[] = [
                    'term_version' => (string) $term['version'],
                    'body_html' => (string) $term['body_html'],
                    'patient_id' => (int) $p['id'],
                    'patient_name' => (string) $p['full_name'],
                ];
            }
        }
        return $out;
    }

    /** @throws \InvalidArgumentException se o par termo/paciente nao esta pendente para o usuario */
    public function accept(int $userId, ?int $patientId, string $version, Request $r): void
    {
        $user = $this->db->one('SELECT * FROM users WHERE id = ?', [$userId]);
        $found = false;
        foreach ($user === null ? [] : $this->pendingFor($user) as $item) {
            if ($item['term_version'] === $version && $item['patient_id'] === $patientId) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            throw new \InvalidArgumentException('Consentimento não pendente.');
        }
        $id = $this->db->insert('consents', [
            'user_id' => $userId,
            'patient_id' => $patientId,
            'term_version' => $version,
            'ip' => mb_substr($r->ip(), 0, 45),
        ]);
        $this->audit->record($userId, 'consent', 'consents', $id, $patientId, null, ['term_version' => $version], $r);
    }
}
