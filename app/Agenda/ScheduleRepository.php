<?php
declare(strict_types=1);

namespace App\Agenda;

use App\Audit\AuditLog;
use App\Core\Database;
use App\Core\Request;

/**
 * Horarios das profissionais: duracao/endereco (professional_schedules, tambem a linha de trava da agenda),
 * semana-modelo (schedule_ranges) e fechamentos/horarios extras (schedule_exceptions).
 * Quem pode chamar cada metodo e decidido por AgendaAccess (nos controllers).
 */
final class ScheduleRepository
{
    public function __construct(private Database $db, private AuditLog $audit)
    {
    }

    /** @return array{slot_minutes:int,address:?string} cria a linha com o padrao se faltar */
    public function profile(int $professionalId): array
    {
        $this->db->run(
            'INSERT IGNORE INTO professional_schedules (professional_id, slot_minutes) VALUES (?, ?)',
            [$professionalId, AgendaStatus::DEFAULT_MINUTES],
        );
        $row = $this->db->one('SELECT slot_minutes, address FROM professional_schedules WHERE professional_id = ?', [$professionalId]);
        return ['slot_minutes' => (int) $row['slot_minutes'], 'address' => $row['address'] === null ? null : (string) $row['address']];
    }

    /** @return list<array> faixas ativas, por dia e inicio */
    public function ranges(int $professionalId): array
    {
        return $this->db->all(
            'SELECT * FROM schedule_ranges WHERE professional_id = ? AND deleted_at IS NULL ORDER BY weekday, start_time, id',
            [$professionalId],
        );
    }

    /**
     * Troca a semana-modelo inteira (exclusao logica das faixas antigas + insercao), a duracao e o endereco.
     *
     * @param list<array{weekday:mixed,start_time:mixed,end_time:mixed}> $ranges
     */
    public function saveWeek(int $professionalId, array $ranges, int $minutes, ?string $address, int $uid, Request $r): void
    {
        $errors = [];
        $clean = $this->cleanRanges($ranges, $errors);
        if ($minutes < 10 || $minutes > 240 || $minutes % 5 !== 0) {
            $errors[] = 'A duração da consulta deve ser de 10 a 240 minutos, em múltiplos de 5.';
        }
        $address = $address === null ? null : trim($address);
        if ($address === '') {
            $address = null;
        }
        if ($address !== null && mb_strlen($address) > 200) {
            $errors[] = 'O endereço deve ter até 200 caracteres.';
        }
        if ($errors !== []) {
            throw new InvalidAgendaException($errors);
        }
        $this->db->transaction(function () use ($professionalId, $clean, $minutes, $address, $uid, $r): void {
            $this->profile($professionalId);
            $this->db->one('SELECT professional_id FROM professional_schedules WHERE professional_id = ? FOR UPDATE', [$professionalId]);
            $before = $this->snapshot($professionalId);
            $this->replaceRanges($professionalId, $clean, $uid);
            $this->db->run(
                'UPDATE professional_schedules SET slot_minutes = ?, address = ?, updated_by = ?, updated_at = NOW() WHERE professional_id = ?',
                [$minutes, $address, $uid, $professionalId],
            );
            $this->audit->record($uid, 'update', 'professional_schedules', $professionalId, null, $before, $this->snapshot($professionalId), $r);
        });
    }

    /** Preenche o horario padrao da clinica (30 min), so se a profissional nao tem nenhuma faixa. */
    public function applyClinicDefault(int $professionalId, int $uid, Request $r): void
    {
        if ($this->ranges($professionalId) !== []) {
            throw new InvalidAgendaException(['Esta agenda já tem horários.']);
        }
        $this->saveWeek($professionalId, AgendaStatus::CLINIC_DEFAULT, AgendaStatus::DEFAULT_MINUTES, $this->profile($professionalId)['address'], $uid, $r);
    }

    /** @return list<array> excecoes nao excluidas cujo periodo cruza [from, to], por data inicial */
    public function exceptions(int $professionalId, string $from, string $to): array
    {
        return $this->db->all(
            'SELECT * FROM schedule_exceptions
             WHERE professional_id = ? AND deleted_at IS NULL AND start_date <= ? AND end_date >= ?
             ORDER BY start_date, start_time, id',
            [$professionalId, $to, $from],
        );
    }

    /** Fechamento: datas a ate 2 anos de $today, horarios ambos ou nenhum (nenhum = dia inteiro). */
    public function addClosure(int $professionalId, string $startDate, string $endDate, ?string $startTime, ?string $endTime, ?string $reason, int $uid, Request $r, string $today): int
    {
        $errors = [];
        $this->checkDates($startDate, $endDate, $today, $errors);
        $startTime = $this->blankToNull($startTime);
        $endTime = $this->blankToNull($endTime);
        if (($startTime === null) !== ($endTime === null)) {
            $errors[] = 'Informe o horário inicial e o final, ou deixe os dois em branco para o dia inteiro.';
        } elseif ($startTime !== null) {
            $this->checkTimes($startTime, $endTime, $errors);
        }
        $reason = $this->blankToNull($reason);
        if ($reason !== null && mb_strlen($reason) > 120) {
            $errors[] = 'O motivo deve ter até 120 caracteres.';
        }
        if ($errors !== []) {
            throw new InvalidAgendaException($errors);
        }
        return $this->insertException($professionalId, 'fechado', $startDate, $endDate, $this->hms($startTime), $this->hms($endTime), $reason, $uid, $r);
    }

    /** Horario extra num dia: horarios obrigatorios. */
    public function addExtra(int $professionalId, string $date, string $startTime, string $endTime, int $uid, Request $r, string $today): int
    {
        $errors = [];
        $this->checkDates($date, $date, $today, $errors);
        $startTime = trim($startTime);
        $endTime = trim($endTime);
        if ($startTime === '' || $endTime === '') {
            $errors[] = 'Informe o horário inicial e o final do horário extra.';
        } else {
            $this->checkTimes($startTime, $endTime, $errors);
        }
        if ($errors !== []) {
            throw new InvalidAgendaException($errors);
        }
        return $this->insertException($professionalId, 'extra', $date, $date, $this->hms($startTime), $this->hms($endTime), null, $uid, $r);
    }

    /** Exclusao logica de um fechamento ou horario extra. */
    public function removeException(int $id, int $uid, Request $r): void
    {
        $before = $this->db->one('SELECT * FROM schedule_exceptions WHERE id = ?', [$id]);
        if ($before === null || $before['deleted_at'] !== null) {
            throw new InvalidAgendaException(['Exceção não encontrada.']);
        }
        $this->db->run('UPDATE schedule_exceptions SET deleted_at = NOW(), deleted_by = ? WHERE id = ?', [$uid, $id]);
        $after = $this->db->one('SELECT * FROM schedule_exceptions WHERE id = ?', [$id]);
        $this->audit->record($uid, 'delete', 'schedule_exceptions', $id, null, $before, $after, $r);
    }

    /** @return list<array{start:string,end:string,source:string}> grade do dia com os dados do banco */
    public function slotsOn(int $professionalId, string $ymd): array
    {
        return SlotCalculator::daySlots(
            $ymd,
            $this->ranges($professionalId),
            $this->exceptions($professionalId, $ymd, $ymd),
            $this->profile($professionalId)['slot_minutes'],
        );
    }

    /** Consultas que ocupam horario dentro do fechamento (cada dia de [startDate, endDate]; sem horario = dia inteiro). */
    public function bookedInClosure(int $professionalId, string $startDate, string $endDate, ?string $startTime, ?string $endTime): int
    {
        $sql = 'SELECT COUNT(*) AS n FROM appointments
                WHERE professional_id = ? AND occupies = 1 AND starts_at >= ? AND starts_at < ?';
        $dayAfter = (new \DateTimeImmutable($endDate))->modify('+1 day')->format('Y-m-d');
        $params = [$professionalId, $startDate . ' 00:00:00', $dayAfter . ' 00:00:00'];
        $startTime = $this->blankToNull($startTime);
        $endTime = $this->blankToNull($endTime);
        if ($startTime !== null && $endTime !== null) {
            $sql .= ' AND TIME(starts_at) < ? AND TIME(ends_at) > ?';
            $params[] = $this->hms($endTime);
            $params[] = $this->hms($startTime);
        }
        return (int) $this->db->one($sql, $params)['n'];
    }

    /** @return array{slot_minutes:int,address:?string,ranges:list<array{weekday:int,start_time:string,end_time:string}>} */
    private function snapshot(int $professionalId): array
    {
        return $this->profile($professionalId) + [
            'ranges' => array_map(
                fn (array $x): array => ['weekday' => (int) $x['weekday'], 'start_time' => substr((string) $x['start_time'], 0, 5), 'end_time' => substr((string) $x['end_time'], 0, 5)],
                $this->ranges($professionalId),
            ),
        ];
    }

    /** @param list<array{weekday:int,start_time:string,end_time:string}> $ranges */
    private function replaceRanges(int $professionalId, array $ranges, int $uid): void
    {
        $this->db->run(
            'UPDATE schedule_ranges SET deleted_at = NOW(), deleted_by = ? WHERE professional_id = ? AND deleted_at IS NULL',
            [$uid, $professionalId],
        );
        foreach ($ranges as $x) {
            $this->db->insert('schedule_ranges', [
                'professional_id' => $professionalId,
                'weekday' => $x['weekday'],
                'start_time' => $this->hms($x['start_time']),
                'end_time' => $this->hms($x['end_time']),
                'created_by' => $uid,
            ]);
        }
    }

    /**
     * @param list<string> $errors
     * @return list<array{weekday:int,start_time:string,end_time:string}> ordenadas por dia e inicio, horarios 'H:i'
     */
    private function cleanRanges(array $ranges, array &$errors): array
    {
        $clean = [];
        foreach ($ranges as $x) {
            $wd = filter_var($x['weekday'] ?? null, FILTER_VALIDATE_INT);
            if ($wd === false || $wd < 1 || $wd > 7) {
                $errors[] = 'Dia da semana inválido.';
                continue;
            }
            $s = trim((string) ($x['start_time'] ?? ''));
            $e = trim((string) ($x['end_time'] ?? ''));
            $before = count($errors);
            $this->checkTimes($s, $e, $errors, AgendaStatus::WEEKDAY_SHORT[$wd]);
            if (count($errors) === $before) {
                $clean[] = ['weekday' => $wd, 'start_time' => substr($s, 0, 5), 'end_time' => substr($e, 0, 5)];
            }
        }
        usort($clean, fn (array $a, array $b): int => [$a['weekday'], $a['start_time']] <=> [$b['weekday'], $b['start_time']]);
        $reported = [];
        for ($i = 1, $n = count($clean); $i < $n; $i++) {
            $prev = $clean[$i - 1];
            $cur = $clean[$i];
            if ($prev['weekday'] === $cur['weekday'] && $cur['start_time'] < $prev['end_time'] && !isset($reported[$cur['weekday']])) {
                $reported[$cur['weekday']] = true;
                $errors[] = 'As faixas de ' . AgendaStatus::WEEKDAY_LONG[$cur['weekday']] . ' se sobrepõem.';
            }
        }
        return $clean;
    }

    /** @param list<string> $errors */
    private function checkTimes(string $start, ?string $end, array &$errors, ?string $day = null): void
    {
        $suffix = $day === null ? '' : ' (' . $day . ')';
        if (!$this->isTime($start) || $end === null || !$this->isTime($end)) {
            $errors[] = 'Informe horários válidos (HH:MM)' . $suffix . '.';
        } elseif (substr($start, 0, 5) >= substr($end, 0, 5)) {
            $errors[] = 'O horário final deve ser depois do inicial' . $suffix . '.';
        }
    }

    /** @param list<string> $errors */
    private function checkDates(string $start, string $end, string $today, array &$errors): void
    {
        if (!$this->isDate($start) || !$this->isDate($end)) {
            $errors[] = 'Informe datas válidas.';
            return;
        }
        if ($end < $start) {
            $errors[] = 'A data final deve ser igual ou posterior à inicial.';
        }
        $t = new \DateTimeImmutable($today);
        $min = $t->modify('-2 years')->format('Y-m-d');
        $max = $t->modify('+2 years')->format('Y-m-d');
        if ($start < $min || $end > $max) {
            $errors[] = 'As datas devem estar a até 2 anos de hoje.';
        }
    }

    private function insertException(int $professionalId, string $kind, string $start, string $end, ?string $startTime, ?string $endTime, ?string $reason, int $uid, Request $r): int
    {
        $id = $this->db->insert('schedule_exceptions', [
            'professional_id' => $professionalId,
            'kind' => $kind,
            'start_date' => $start,
            'end_date' => $end,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'reason' => $reason,
            'created_by' => $uid,
        ]);
        $this->audit->record($uid, 'create', 'schedule_exceptions', $id, null, null, $this->db->one('SELECT * FROM schedule_exceptions WHERE id = ?', [$id]), $r);
        return $id;
    }

    private function blankToNull(?string $v): ?string
    {
        $v = $v === null ? null : trim($v);
        return $v === '' ? null : $v;
    }

    private function hms(?string $hm): ?string
    {
        return $hm === null ? null : substr($hm, 0, 5) . ':00';
    }

    private function isTime(string $v): bool
    {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d(:00)?$/', $v) === 1;
    }

    private function isDate(string $v): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        return $d !== false && $d->format('Y-m-d') === $v;
    }
}
