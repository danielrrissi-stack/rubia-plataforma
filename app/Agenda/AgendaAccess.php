<?php
declare(strict_types=1);

namespace App\Agenda;

use App\Access\PatientScope;
use App\Core\Database;

/**
 * Quem ve e mexe em qual agenda (spec §5.9). Unica regra de "qual agenda"; a Policy so libera a secao AGENDA.
 * Medica administradora = role medico + is_admin = 1.
 */
final class AgendaAccess
{
    public function __construct(private Database $db, private PatientScope $scope)
    {
    }

    /** @return list<array{id:int,name:string,role:string}> profissionais ativas (medico/nutricionista), por nome */
    public function professionals(): array
    {
        return array_map(
            fn (array $u): array => ['id' => (int) $u['id'], 'name' => (string) $u['name'], 'role' => (string) $u['role']],
            $this->db->all("SELECT id, name, role FROM users WHERE active = 1 AND role IN ('medico','nutricionista') ORDER BY name, id"),
        );
    }

    /** @return list<array{id:int,name:string,role:string}> */
    public function agendasFor(array $user): array
    {
        if (!$this->isActive($user)) {
            return [];
        }
        if ($this->isSecretary($user) || $this->isAdminDoctor($user)) {
            return $this->professionals();
        }
        if (in_array((string) $user['role'], ['medico', 'nutricionista'], true)) {
            return array_values(array_filter($this->professionals(), fn (array $p): bool => $p['id'] === (int) $user['id']));
        }
        return [];
    }

    public function canView(array $user, int $professionalId): bool
    {
        foreach ($this->agendasFor($user) as $p) {
            if ($p['id'] === $professionalId) {
                return true;
            }
        }
        return false;
    }

    /** Marcar/mudar consultas: mesma regra de ver a agenda. */
    public function canManage(array $user, int $professionalId): bool
    {
        return $this->canView($user, $professionalId);
    }

    /**
     * Mudar consulta ja marcada (situacao, cancelar, origem da remarcacao): como canManage, mas vale tambem para
     * profissional desativada (spec §8: as consultas dela ficam no historico). Secretaria e medica administradora:
     * qualquer profissional (medico/nutricionista), ativa ou nao; medica/nutricionista: so a propria.
     */
    public function canManageExisting(array $user, int $professionalId): bool
    {
        if (!$this->isActive($user)) {
            return false;
        }
        if ($this->isSecretary($user) || $this->isAdminDoctor($user)) {
            return $this->db->one("SELECT 1 AS n FROM users WHERE id = ? AND role IN ('medico','nutricionista')", [$professionalId]) !== null;
        }
        return in_array((string) $user['role'], ['medico', 'nutricionista'], true) && (int) $user['id'] === $professionalId;
    }

    /** Semana-modelo, duracao e endereco: a propria profissional ou a medica administradora. */
    public function canEditWeek(array $user, int $professionalId): bool
    {
        if (!$this->canView($user, $professionalId)) {
            return false;
        }
        return $this->isAdminDoctor($user)
            || (in_array((string) $user['role'], ['medico', 'nutricionista'], true) && (int) $user['id'] === $professionalId);
    }

    /** Fechamentos e horarios extras: quem edita a semana ou a secretaria. */
    public function canAddClosure(array $user, int $professionalId): bool
    {
        return $this->canEditWeek($user, $professionalId)
            || ($this->isSecretary($user) && $this->canView($user, $professionalId));
    }

    /** null = pode marcar; senao a mensagem de recusa. */
    public function mayBookPatient(int $professionalId, int $patientId, array $user): ?string
    {
        if (!$this->scope->canAccess($user, $patientId)) {
            return 'Paciente não encontrado.';
        }
        if (!$this->patientVisibleOnAgenda($professionalId, $patientId)) {
            return 'Peça à médica para liberar este paciente para a nutricionista.';
        }
        return null;
    }

    /** Nutricionista: so com liberacao ativa (professional_access); medica: sempre. */
    public function patientVisibleOnAgenda(int $professionalId, int $patientId): bool
    {
        $prof = $this->db->one('SELECT role FROM users WHERE id = ?', [$professionalId]);
        if ($prof === null) {
            return false;
        }
        if ((string) $prof['role'] !== 'nutricionista') {
            return true;
        }
        return $this->db->one(
            'SELECT 1 AS n FROM professional_access WHERE professional_id = ? AND patient_id = ? AND revoked_at IS NULL LIMIT 1',
            [$professionalId, $patientId],
        ) !== null;
    }

    /** Textos do aviso: so a medica administradora. */
    public function canEditTexts(array $user): bool
    {
        return $this->isAdminDoctor($user);
    }

    private function isActive(array $user): bool
    {
        return (int) ($user['active'] ?? 0) === 1 && isset($user['id']);
    }

    private function isAdminDoctor(array $user): bool
    {
        return $this->isActive($user) && (string) ($user['role'] ?? '') === 'medico' && (int) ($user['is_admin'] ?? 0) === 1;
    }

    private function isSecretary(array $user): bool
    {
        return $this->isActive($user) && (string) ($user['role'] ?? '') === 'secretaria';
    }
}
