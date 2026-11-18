<?php
declare(strict_types=1);

namespace App\Agenda;

use App\Audit\AuditLog;
use App\Core\Database;
use App\Core\Request;

/**
 * Consultas da agenda: marcar sem choque de horario, situacoes, cancelar e remarcar.
 * Choque: sobreposicao de intervalos verificada com a agenda travada (professional_schedules FOR UPDATE) e o
 * indice unico uq_appt_slot como segunda barreira. "Agora" sempre chega por parametro ('Y-m-d H:i').
 * Nao envia e-mail (AgendaMailer, nos controllers).
 */
final class AppointmentRepository
{
    private const BUSY = 'Este horário não está mais livre.';
    private const NOT_ALLOWED = 'Esta mudança de situação não é permitida.';
    private const CANNOT_CHANGE = 'Você não pode mudar esta consulta.';

    private const SELECT = 'SELECT a.*, p.full_name AS patient_name, p.social_name, p.birth_date, p.phone AS patient_phone,
            p.email AS patient_email, p.user_id AS patient_user_id,
            u.name AS professional_name, u.role AS professional_role, u.email AS professional_email,
            ps.address AS professional_address
        FROM appointments a
        JOIN patients p ON p.id = a.patient_id
        JOIN users u ON u.id = a.professional_id
        LEFT JOIN professional_schedules ps ON ps.professional_id = a.professional_id';

    public function __construct(
        private Database $db,
        private AuditLog $audit,
        private ScheduleRepository $schedules,
        private AgendaAccess $access,
    ) {
    }

    /** Marca uma consulta num horario aberto e livre, a partir de agora. Devolve o id. */
    public function book(int $professionalId, int $patientId, string $startsAt, string $kind, ?string $note, array $user, string $now, Request $r, ?int $rescheduledFrom = null): int
    {
        if (!$this->access->canManage($user, $professionalId)) {
            throw new InvalidAgendaException(['Você não pode marcar nesta agenda.']);
        }
        return (int) $this->db->transaction(function () use ($professionalId, $patientId, $startsAt, $kind, $note, $user, $now, $r, $rescheduledFrom): int {
            $this->lockAgendas([$professionalId]);
            $note = $this->cleanNote($note);
            $endsAt = $this->validateBooking($professionalId, $patientId, $startsAt, $kind, $note, $user, $now, null);
            return $this->insertBooking($professionalId, $patientId, $startsAt, $endsAt, $kind, $note, $user, $r, $rescheduledFrom);
        });
    }

    public function find(int $id): ?array
    {
        return $this->db->one(self::SELECT . ' WHERE a.id = ?', [$id]);
    }

    public function setStatus(int $id, string $status, array $user, string $now, Request $r): void
    {
        $this->db->transaction(function () use ($id, $status, $user, $now, $r): void {
            $before = $this->lockedForChange($id, $user);
            if (!$this->transitionAllowed((string) $before['status'], $status, $before, $now)) {
                throw new InvalidAgendaException([self::NOT_ALLOWED]);
            }
            $this->db->run(
                'UPDATE appointments SET status = ?, status_changed_at = ?, updated_by = ?, updated_at = NOW() WHERE id = ?',
                [$status, $now . ':00', (int) $user['id'], $id],
            );
            $this->audit->record((int) $user['id'], 'status', 'appointments', $id, (int) $before['patient_id'], $before, $this->raw($id), $r);
        });
    }

    public function cancel(int $id, string $reason, array $user, string $now, Request $r): void
    {
        $reason = trim($reason);
        $this->db->transaction(function () use ($id, $reason, $user, $now, $r): void {
            $before = $this->lockedForChange($id, $user);
            if (!in_array((string) $before['status'], AgendaStatus::REMINDABLE, true)) {
                throw new InvalidAgendaException(['Esta consulta não pode ser cancelada.']);
            }
            if ($reason === '' || mb_strlen($reason) > 255) {
                throw new InvalidAgendaException(['Informe o motivo do cancelamento.']);
            }
            $this->db->run(
                "UPDATE appointments SET status = 'cancelada', cancel_reason = ?, status_changed_at = ?, updated_by = ?, updated_at = NOW() WHERE id = ?",
                [$reason, $now . ':00', (int) $user['id'], $id],
            );
            $this->audit->record((int) $user['id'], 'cancel', 'appointments', $id, (int) $before['patient_id'], $before, $this->raw($id), $r);
        });
    }

    /** Remarca (mesma ou outra profissional): nova consulta com o mesmo paciente, tipo e nota; a antiga vira remarcada. */
    public function reschedule(int $id, int $newProfessionalId, string $newStartsAt, array $user, string $now, Request $r): int
    {
        $current = $this->raw($id);
        if ($current === null) {
            throw new InvalidAgendaException(['Consulta não encontrada.']);
        }
        if (!$this->access->canManageExisting($user, (int) $current['professional_id'])) {
            throw new InvalidAgendaException([self::CANNOT_CHANGE]);
        }
        if (!$this->access->canManage($user, $newProfessionalId)) {
            throw new InvalidAgendaException(['Você não pode marcar nesta agenda.']);
        }
        return (int) $this->db->transaction(function () use ($id, $current, $newProfessionalId, $newStartsAt, $user, $now, $r): int {
            // as duas agendas, sempre em ordem crescente de id, para duas remarcacoes cruzadas nao travarem em circulo
            $this->lockAgendas([(int) $current['professional_id'], $newProfessionalId]);
            $before = $this->db->one('SELECT * FROM appointments WHERE id = ? FOR UPDATE', [$id]);
            if ((int) $before['professional_id'] !== (int) $current['professional_id']) {
                throw new InvalidAgendaException([self::CANNOT_CHANGE]);
            }
            if (!in_array((string) $before['status'], AgendaStatus::REMINDABLE, true)) {
                throw new InvalidAgendaException(['Esta consulta não pode ser remarcada.']);
            }
            $patientId = (int) $before['patient_id'];
            $kind = (string) $before['kind'];
            $note = $before['note'] === null ? null : (string) $before['note'];
            // valida tudo antes de mexer; a antiga nao conta como choque (ela sera liberada)
            $endsAt = $this->validateBooking($newProfessionalId, $patientId, $newStartsAt, $kind, $note, $user, $now, $id);
            $uid = (int) $user['id'];
            $this->db->run(
                "UPDATE appointments SET status = 'remarcada', status_changed_at = ?, updated_by = ?, updated_at = NOW() WHERE id = ?",
                [$now . ':00', $uid, $id],
            );
            $newId = $this->insertBooking($newProfessionalId, $patientId, $newStartsAt, $endsAt, $kind, $note, $user, $r, $id);
            $this->db->run('UPDATE appointments SET rescheduled_to_id = ? WHERE id = ?', [$newId, $id]);
            $this->audit->record($uid, 'reschedule', 'appointments', $id, $patientId, $before, $this->raw($id), $r);
            return $newId;
        });
    }

    /** @return list<array> todas as situacoes, por inicio */
    public function forDay(int $professionalId, string $ymd): array
    {
        return $this->forRange($professionalId, $ymd, $ymd);
    }

    /** @return list<array> todas as situacoes de [from, to] (datas 'Y-m-d' inclusivas), por inicio */
    public function forRange(int $professionalId, string $from, string $to): array
    {
        return $this->db->all(
            self::SELECT . ' WHERE a.professional_id = ? AND a.starts_at >= ? AND a.starts_at < ? ORDER BY a.starts_at, a.id',
            [$professionalId, $from . ' 00:00:00', $this->nextDay($to) . ' 00:00:00'],
        );
    }

    /**
     * @return array{upcoming: list<array>, past: list<array>} proximas que ocupam horario (crescente) e as ultimas 5
     *         passadas de qualquer situacao (decrescente); $onlyProfessionalId filtra por profissional.
     */
    public function forPatient(int $patientId, ?int $onlyProfessionalId, string $now): array
    {
        $where = ' WHERE a.patient_id = ?';
        $params = [$patientId];
        if ($onlyProfessionalId !== null) {
            $where .= ' AND a.professional_id = ?';
            $params[] = $onlyProfessionalId;
        }
        $at = $now . ':00';
        return [
            'upcoming' => $this->db->all(self::SELECT . $where . ' AND a.occupies = 1 AND a.starts_at >= ? ORDER BY a.starts_at, a.id', [...$params, $at]),
            'past' => $this->db->all(self::SELECT . $where . ' AND a.starts_at < ? ORDER BY a.starts_at DESC, a.id DESC LIMIT 5', [...$params, $at]),
        ];
    }

    /** "retorno" se o paciente tem consulta realizada com a profissional, senao "primeira". */
    public function suggestedKind(int $professionalId, int $patientId): string
    {
        $done = $this->db->one(
            "SELECT 1 AS n FROM appointments WHERE professional_id = ? AND patient_id = ? AND status = 'realizada' LIMIT 1",
            [$professionalId, $patientId],
        );
        return $done === null ? 'primeira' : 'retorno';
    }

    /** @return list<array> consultas agendada/confirmada do dia, por profissional e hora */
    public function remindable(string $ymd): array
    {
        return $this->db->all(
            self::SELECT . " WHERE a.status IN ('agendada','confirmada') AND a.starts_at >= ? AND a.starts_at < ?
              ORDER BY a.professional_id, a.starts_at, a.id",
            [$ymd . ' 00:00:00', $this->nextDay($ymd) . ' 00:00:00'],
        );
    }

    /**
     * Grade do dia com as consultas. Horario da grade: `livre`, `passado` (livre ja passado) ou `marcado` (consulta
     * que ocupa comecando nele); horario coberto por consulta que comeca em outra hora some (nao fica livre).
     * Consultas fora da grade aparecem na hora delas com flags `fora_do_horario` e/ou `em_fechamento`;
     * canceladas/remarcadas aparecem com a flag `riscada` (state `marcado`), sem ocupar, antes do horario do mesmo inicio.
     *
     * @return list<array{start:string,end:string,state:string,appointment:?array,flags:list<string>}>
     */
    public function dayView(int $professionalId, string $ymd, string $now): array
    {
        $slots = $this->schedules->slotsOn($professionalId, $ymd);
        $exceptions = $this->schedules->exceptions($professionalId, $ymd, $ymd);
        $openGrid = array_column(SlotCalculator::daySlots(
            $ymd,
            $this->schedules->ranges($professionalId),
            array_values(array_filter($exceptions, fn (array $x): bool => (string) $x['kind'] !== 'fechado')),
            $this->schedules->profile($professionalId)['slot_minutes'],
        ), 'start');
        $appointments = $this->forDay($professionalId, $ymd);

        $entries = [];
        foreach ($appointments as $a) {
            $start = substr((string) $a['starts_at'], 11, 5);
            $end = substr((string) $a['ends_at'], 11, 5);
            $flags = [];
            $occupies = AgendaStatus::occupies((string) $a['status']);
            if (!$occupies) {
                $flags[] = 'riscada';
            }
            if (!in_array($start, $openGrid, true)) {
                $flags[] = 'fora_do_horario';
            }
            if (SlotCalculator::closedOn($ymd, $start, $end, $exceptions)) {
                $flags[] = 'em_fechamento';
            }
            $entries[] = ['start' => $start, 'end' => $end, 'state' => 'marcado', 'appointment' => $a, 'flags' => $flags, 'order' => $occupies ? 1 : 0];
        }
        $occupying = array_values(array_filter($appointments, fn (array $a): bool => AgendaStatus::occupies((string) $a['status'])));
        foreach ($slots as $s) {
            $covered = false;
            foreach ($occupying as $a) {
                if (SlotCalculator::overlaps($s['start'], $s['end'], substr((string) $a['starts_at'], 11, 5), substr((string) $a['ends_at'], 11, 5))) {
                    $covered = true;
                    break;
                }
            }
            if (!$covered) {
                $state = ($ymd . ' ' . $s['start']) <= $now ? 'passado' : 'livre';
                $entries[] = ['start' => $s['start'], 'end' => $s['end'], 'state' => $state, 'appointment' => null, 'flags' => [], 'order' => 2];
            }
        }
        usort($entries, fn (array $x, array $y): int => [$x['start'], $x['order']] <=> [$y['start'], $y['order']]);
        return array_map(function (array $e): array {
            unset($e['order']);
            return $e;
        }, $entries);
    }

    /** Trava as linhas de agenda (criadas se faltarem) em ordem crescente de id. @param list<int> $professionalIds */
    private function lockAgendas(array $professionalIds): void
    {
        $ids = array_values(array_unique($professionalIds));
        sort($ids);
        foreach ($ids as $pid) {
            $this->schedules->profile($pid);
            $this->db->one('SELECT professional_id FROM professional_schedules WHERE professional_id = ? FOR UPDATE', [$pid]);
        }
    }

    /**
     * Regras de marcacao (com a agenda ja travada). Devolve ends_at 'Y-m-d H:i:s'.
     * $ignoreId: consulta que sera liberada na mesma transacao (remarcacao) e nao conta como choque.
     */
    private function validateBooking(int $professionalId, int $patientId, string $startsAt, string $kind, ?string $note, array $user, string $now, ?int $ignoreId): string
    {
        $refusal = $this->access->mayBookPatient($professionalId, $patientId, $user);
        if ($refusal !== null) {
            throw new InvalidAgendaException([$refusal]);
        }
        if (!in_array($kind, AgendaStatus::KINDS, true)) {
            throw new InvalidAgendaException(['Tipo de consulta inválido.']);
        }
        if ($note !== null && mb_strlen($note) > 255) {
            throw new InvalidAgendaException(['A observação deve ter até 255 caracteres.']);
        }
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $startsAt);
        if ($start === false || $start->format('Y-m-d H:i') !== $startsAt) {
            throw new InvalidAgendaException(['Este horário não está aberto na agenda.']);
        }
        if ($startsAt <= $now) {
            throw new InvalidAgendaException(['Escolha um horário a partir de agora.']);
        }
        $ymd = substr($startsAt, 0, 10);
        $hm = substr($startsAt, 11, 5);
        if (!in_array($hm, array_column($this->schedules->slotsOn($professionalId, $ymd), 'start'), true)) {
            throw new InvalidAgendaException(['Este horário não está aberto na agenda.']);
        }
        $endsAt = $start->modify('+' . $this->schedules->profile($professionalId)['slot_minutes'] . ' minutes')->format('Y-m-d H:i:s');
        $clash = $this->db->one(
            'SELECT id FROM appointments WHERE professional_id = ? AND occupies = 1 AND starts_at < ? AND ends_at > ? AND id <> ? LIMIT 1',
            [$professionalId, $endsAt, $startsAt . ':00', $ignoreId ?? 0],
        );
        if ($clash !== null) {
            throw new InvalidAgendaException([self::BUSY]);
        }
        return $endsAt;
    }

    private function insertBooking(int $professionalId, int $patientId, string $startsAt, string $endsAt, string $kind, ?string $note, array $user, Request $r, ?int $rescheduledFrom): int
    {
        $uid = (int) $user['id'];
        try {
            $id = $this->db->insert('appointments', [
                'professional_id' => $professionalId,
                'patient_id' => $patientId,
                'starts_at' => $startsAt . ':00',
                'ends_at' => $endsAt,
                'kind' => $kind,
                'status' => 'agendada',
                'note' => $note,
                'rescheduled_from_id' => $rescheduledFrom,
                'created_by' => $uid,
            ]);
        } catch (\PDOException $e) {
            // segunda barreira: indice unico (profissional, inicio) entre consultas que ocupam horario
            if ($e->getCode() === '23000' && (int) ($e->errorInfo[1] ?? 0) === 1062) {
                throw new InvalidAgendaException([self::BUSY]);
            }
            throw $e;
        }
        $this->audit->record($uid, 'create', 'appointments', $id, $patientId, null, $this->raw($id), $r);
        return $id;
    }

    /** Linha travada para mudar situacao/cancelar; confere existencia e permissao (inclui profissional desativada). */
    private function lockedForChange(int $id, array $user): array
    {
        $row = $this->db->one('SELECT * FROM appointments WHERE id = ? FOR UPDATE', [$id]);
        if ($row === null) {
            throw new InvalidAgendaException(['Consulta não encontrada.']);
        }
        if (!$this->access->canManageExisting($user, (int) $row['professional_id'])) {
            throw new InvalidAgendaException([self::CANNOT_CHANGE]);
        }
        return $row;
    }

    /**
     * confirmada: de agendada, antes do fim; realizada/faltou: de agendada/confirmada, no dia ou depois;
     * agendada: de realizada/faltou, so no proprio dia (desfazer). Todo o resto e recusado.
     */
    private function transitionAllowed(string $from, string $to, array $row, string $now): bool
    {
        $day = substr((string) $row['starts_at'], 0, 10);
        $today = substr($now, 0, 10);
        return match ($to) {
            'confirmada' => $from === 'agendada' && $now . ':00' < (string) $row['ends_at'],
            'realizada', 'faltou' => in_array($from, ['agendada', 'confirmada'], true) && $day <= $today,
            'agendada' => in_array($from, ['realizada', 'faltou'], true) && $day === $today,
            default => false,
        };
    }

    private function raw(int $id): ?array
    {
        return $this->db->one('SELECT * FROM appointments WHERE id = ?', [$id]);
    }

    private function cleanNote(?string $note): ?string
    {
        $note = $note === null ? null : trim($note);
        return $note === '' ? null : $note;
    }

    private function nextDay(string $ymd): string
    {
        return (new \DateTimeImmutable($ymd))->modify('+1 day')->format('Y-m-d');
    }
}
