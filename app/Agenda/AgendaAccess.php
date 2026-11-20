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

    /**
     * Agendas que o usuario ve. Secretaria e medica administradora: todas as profissionais ativas (por nome) e, no
     * fim, as desativadas que ainda tem consulta futura agendada/confirmada (para cancelar ou remarcar; nao se marca
     * nelas). Medica/nutricionista: so a propria. `active` distingue as desativadas. $now 'Y-m-d H:i' (padrao: agora).
     *
     * @return list<array{id:int,name:string,role:string,active:bool}>
     */
    public function agendasFor(array $user, ?string $now = null): array
    {
        if (!$this->isActive($user)) {
            return [];
        }
        $active = array_map(fn (array $p): array => $p + ['active' => true], $this->professionals());
        if ($this->isSecretary($user) || $this->isAdminDoctor($user)) {
            return array_merge($active, $this->inactiveWithFutureAppointments($now ?? date('Y-m-d H:i')));
        }
        if (in_array((string) $user['role'], ['medico', 'nutricionista'], true)) {
            return array_values(array_filter($active, fn (array $p): bool => $p['id'] === (int) $user['id']));
        }
        return [];
    }

    /**
     * Agenda em que o botao "Agendar" do paciente marca: a propria (se der) ou a primeira da lista em que
     * mayBookPatient libera; so agendas ativas. null = nenhuma.
     */
    public function firstBookableAgenda(array $user, int $patientId): ?int
    {
        $agendas = array_values(array_filter($this->agendasFor($user), fn (array $p): bool => $p['active']));
        usort($agendas, fn (array $a, array $b): int => (int) ($b['id'] === (int) ($user['id'] ?? 0)) <=> (int) ($a['id'] === (int) ($user['id'] ?? 0)));
        foreach ($agendas as $p) {
            if ($this->mayBookPatient($p['id'], $patientId, $user) === null) {
                return $p['id'];
            }
        }
        return null;
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

    /** Marcar consultas: ver a agenda e a profissional estar ativa. */
    public function canManage(array $user, int $professionalId): bool
    {
        foreach ($this->agendasFor($user) as $p) {
            if ($p['id'] === $professionalId) {
                return $p['active'];
            }
        }
        return false;
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

    /** Semana-modelo, duracao, endereco e horarios extras: a propria profissional ou a medica administradora (ativa). */
    public function canEditWeek(array $user, int $professionalId): bool
    {
        if (!$this->canManage($user, $professionalId)) {
            return false;
        }
        return $this->isAdminDoctor($user)
            || (in_array((string) $user['role'], ['medico', 'nutricionista'], true) && (int) $user['id'] === $professionalId);
    }

    /** Fechamentos (lancar e remover): quem edita a semana ou a secretaria (profissional ativa). */
    public function canAddClosure(array $user, int $professionalId): bool
    {
        return $this->canEditWeek($user, $professionalId)
            || ($this->isSecretary($user) && $this->canManage($user, $professionalId));
    }

    /** Horarios extras (lancar e remover): so quem edita a semana; a secretaria nao. */
    public function canAddExtra(array $user, int $professionalId): bool
    {
        return $this->canEditWeek($user, $professionalId);
    }

    /** null = pode marcar; senao a mensagem de recusa. */
    public function mayBookPatient(int $professionalId, int $patientId, array $user): ?string
    {
        if (!$this->scope->canAccess($user, $patientId)) {
            return 'Paciente não encontrado.';
        }
        if ($this->db->one('SELECT 1 AS n FROM users WHERE id = ? AND active = 1', [$professionalId]) === null) {
            return 'Esta profissional está desativada: não é possível marcar na agenda dela.';
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

    /** @return list<array{id:int,name:string,role:string,active:bool}> */
    private function inactiveWithFutureAppointments(string $now): array
    {
        return array_map(
            fn (array $u): array => ['id' => (int) $u['id'], 'name' => (string) $u['name'], 'role' => (string) $u['role'], 'active' => false],
            $this->db->all(
                "SELECT u.id, u.name, u.role FROM users u
                 WHERE u.active = 0 AND u.role IN ('medico','nutricionista')
                   AND EXISTS (SELECT 1 FROM appointments a WHERE a.professional_id = u.id
                               AND a.status IN ('agendada','confirmada') AND a.starts_at >= ?)
                 ORDER BY u.name, u.id",
                [$now . ':00'],
            ),
        );
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
