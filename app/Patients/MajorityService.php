<?php
declare(strict_types=1);

namespace App\Patients;

use App\Audit\AuditLog;
use App\Core\Database;
use App\Core\Request;
use App\Growth\Age;
use App\Users\UserRepository;

/** Decisao sobre o acesso dos responsaveis quando o paciente completa 18 anos. */
final class MajorityService
{
    public const DECISIONS = ['encerrar', 'transferir', 'manter'];

    public function __construct(
        private Database $db,
        private AuditLog $audit,
        private GuardianRepository $guardians,
        private UserRepository $users,
    ) {
    }

    /** Pacientes com 18+ anos completos em $today, com vinculo ativo e sem decisao registrada. */
    public function pending(string $today): array
    {
        $rows = $this->db->all(
            'SELECT p.* FROM patients p
             WHERE p.deleted_at IS NULL
               AND EXISTS (SELECT 1 FROM guardian_links g WHERE g.patient_id = p.id AND g.active = 1)
               AND NOT EXISTS (SELECT 1 FROM majority_decisions m WHERE m.patient_id = p.id)
             ORDER BY p.birth_date, p.id'
        );
        return array_values(array_filter(
            $rows,
            static fn(array $p): bool => Age::years((string) $p['birth_date'], $today) >= 18
        ));
    }

    /** @throws \InvalidArgumentException em dados invalidos (nada e gravado) */
    public function decide(int $patientId, string $decision, ?string $notes, int $by, ?string $patientEmail = null, ?Request $r = null): void
    {
        if (!in_array($decision, self::DECISIONS, true)) {
            throw new \InvalidArgumentException('Decisão inválida.');
        }
        $notes = $notes === null || trim($notes) === '' ? null : trim($notes);
        $patient = $this->db->one('SELECT * FROM patients WHERE id = ? AND deleted_at IS NULL', [$patientId]);
        if ($patient === null) {
            throw new \InvalidArgumentException('Paciente não encontrado.');
        }
        if (Age::years((string) $patient['birth_date'], date('Y-m-d')) < 18) {
            throw new \InvalidArgumentException('O paciente ainda não completou 18 anos.');
        }
        if ($this->db->one('SELECT 1 FROM majority_decisions WHERE patient_id = ?', [$patientId]) !== null) {
            throw new \InvalidArgumentException('Já existe uma decisão registrada para este paciente.');
        }

        $email = null;
        if ($decision === 'transferir' && $patient['user_id'] === null) {
            $email = UserRepository::normalizeEmail((string) $patientEmail);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
                throw new \InvalidArgumentException('Informe um e-mail válido para o acesso do paciente.');
            }
            if ($this->users->findByEmail($email) !== null) {
                throw new \InvalidArgumentException('Já existe um usuário com este e-mail.');
            }
        }

        $this->db->transaction(function () use ($patient, $patientId, $decision, $notes, $by, $email, $r): void {
            if ($decision !== 'manter') {
                foreach ($this->guardians->forPatient($patientId) as $link) {
                    $this->guardians->unlink((int) $link['id'], $by, $r);
                }
            }
            $invite = null;
            if ($email !== null) {
                $uid = $this->users->create([
                    'name' => $patient['social_name'] ?: $patient['full_name'],
                    'email' => $email, 'role' => 'paciente', 'password' => null, 'active' => 1,
                ]);
                $this->db->run('UPDATE patients SET user_id = ? WHERE id = ?', [$uid, $patientId]);
                $this->audit->record($by, 'create', 'users', $uid, $patientId, null,
                    ['email' => $email, 'role' => 'paciente'], $r);
                $invite = $this->users->find($uid);
            }
            $id = $this->db->insert('majority_decisions', [
                'patient_id' => $patientId, 'decision' => $decision, 'notes' => $notes, 'decided_by' => $by,
            ]);
            $this->audit->record($by, 'majority_decision', 'majority_decisions', $id, $patientId, null,
                ['decision' => $decision, 'notes' => $notes], $r);
            if ($invite !== null) {
                FamilyInvites::send($invite);
            }
        });
    }
}
