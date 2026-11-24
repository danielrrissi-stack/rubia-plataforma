<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Agenda\AgendaStatus;
use App\Agenda\InvalidAgendaException;
use App\Core\Container;
use App\Core\Request;
use Tests\DbTestCase;

final class AppointmentRepositoryTest extends DbTestCase
{
    /** segunda-feira */
    private const NOW = '2026-11-16 09:00';
    private const TODAY = '2026-11-16';
    private const BUSY = 'Este horário não está mais livre.';
    private const CLOSED = 'Este horário não está aberto na agenda.';
    private const NOT_ALLOWED = 'Esta mudança de situação não é permitida.';

    private array $doc;
    private array $nutri;
    private array $sec;
    private array $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->doc = $this->makeUser('medico', ['name' => 'Dra. Teste']);
        $this->nutri = $this->makeUser('nutricionista', ['name' => 'Nutri Teste']);
        $this->sec = $this->makeUser('secretaria');
        $this->patient = $this->makePatient(['full_name' => 'Ana Paciente']);
        Container::schedules()->applyClinicDefault($this->docId(), $this->docId(), $this->r());
        Container::schedules()->applyClinicDefault($this->nutriId(), $this->nutriId(), $this->r());
    }

    private function r(): Request
    {
        return new Request('POST', '/');
    }

    private function docId(): int
    {
        return (int) $this->doc['id'];
    }

    private function nutriId(): int
    {
        return (int) $this->nutri['id'];
    }

    private function patId(): int
    {
        return (int) $this->patient['id'];
    }

    private function audits(string $action): int
    {
        return (int) $this->db->one("SELECT COUNT(*) n FROM audit_log WHERE action = ? AND entity = 'appointments'", [$action])['n'];
    }

    private function total(): int
    {
        return (int) $this->db->one('SELECT COUNT(*) n FROM appointments')['n'];
    }

    private function row(int $id): array
    {
        return $this->db->one('SELECT * FROM appointments WHERE id = ?', [$id]);
    }

    private function rejects(callable $fn, string $message): void
    {
        try {
            $fn();
            $this->fail('Esperava InvalidAgendaException: ' . $message);
        } catch (InvalidAgendaException $e) {
            $this->assertContains($message, $e->errors());
        }
    }

    private function book(string $startsAt, ?int $prof = null, ?array $user = null, string $now = self::NOW, string $kind = 'primeira', ?string $note = null, ?int $patient = null): int
    {
        return Container::appointments()->book($prof ?? $this->docId(), $patient ?? $this->patId(), $startsAt, $kind, $note, $user ?? $this->sec, $now, $this->r());
    }

    /** @return array<string,list<array>> entradas do dayView indexadas pelo inicio */
    private function view(string $ymd, string $now = self::NOW, ?int $prof = null): array
    {
        $out = [];
        foreach (Container::appointments()->dayView($prof ?? $this->docId(), $ymd, $now) as $e) {
            $out[$e['start']][] = $e;
        }
        return $out;
    }

    private function raw(int $prof, string $start, string $end, string $status, ?int $patient = null): int
    {
        return $this->db->insert('appointments', [
            'professional_id' => $prof, 'patient_id' => $patient ?? $this->patId(), 'kind' => 'primeira',
            'created_by' => $this->sec['id'], 'starts_at' => $start, 'ends_at' => $end, 'status' => $status,
        ]);
    }

    public function test_book_only_open_future_slots(): void
    {
        $this->rejects(fn () => $this->book('2026-11-16 08:00'), 'Escolha um horário a partir de agora.');
        $this->rejects(fn () => $this->book('2026-11-16 09:00'), 'Escolha um horário a partir de agora.');
        $this->rejects(fn () => $this->book('2026-11-16 12:00'), self::CLOSED);
        $this->rejects(fn () => $this->book('2026-11-22 10:00'), self::CLOSED);
        $this->rejects(fn () => $this->book('2026-11-17 08:10'), self::CLOSED);
        $this->rejects(fn () => $this->book('2026-11-17 amanha'), self::CLOSED);
        $this->rejects(fn () => $this->book('2026-11-17 10:00', null, null, self::NOW, 'consulta'), 'Tipo de consulta inválido.');
        $this->rejects(fn () => $this->book('2026-11-17 10:00', null, null, self::NOW, 'primeira', str_repeat('x', 256)), 'A observação deve ter até 255 caracteres.');
        $this->rejects(fn () => $this->book('2026-11-17 10:00', null, $this->makeUser('medico')), 'Você não pode marcar nesta agenda.');
        $this->rejects(fn () => $this->book('2026-11-17 10:00', $this->nutriId()), 'Peça à médica para liberar este paciente para a nutricionista.');
        $this->rejects(fn () => $this->book('2026-11-17 10:00', null, null, self::NOW, 'primeira', null, 999999), 'Paciente não encontrado.');
        $this->assertSame(0, $this->total());
        $this->assertSame(0, $this->audits('create'));

        $id = $this->book('2026-11-16 10:00', null, null, self::NOW, 'retorno', '  pagar na recepção ');
        $row = $this->row($id);
        $this->assertSame('2026-11-16 10:00:00', $row['starts_at']);
        $this->assertSame('2026-11-16 10:30:00', $row['ends_at']);
        $this->assertSame('agendada', $row['status']);
        $this->assertSame('retorno', $row['kind']);
        $this->assertSame('pagar na recepção', $row['note']);
        $this->assertSame((int) $this->sec['id'], (int) $row['created_by']);
        $this->assertSame(1, $this->audits('create'));
        $audit = $this->db->one("SELECT * FROM audit_log WHERE action = 'create' AND entity = 'appointments'");
        $this->assertSame($id, (int) $audit['entity_id']);
        $this->assertSame($this->patId(), (int) $audit['patient_id']);

        $found = Container::appointments()->find($id);
        $this->assertSame('Ana Paciente', $found['patient_name']);
        $this->assertSame('Dra. Teste', $found['professional_name']);
        $this->assertSame('medico', $found['professional_role']);
        $this->assertArrayHasKey('social_name', $found);
        $this->assertSame('2015-03-10', $found['birth_date']);
        $this->assertNull(Container::appointments()->find(999999));

        // a propria medica marca na propria agenda
        $this->book('2026-11-16 10:30', null, $this->doc);
        $this->assertSame(2, $this->total());
    }

    public function test_same_slot_twice_keeps_one(): void
    {
        $id = $this->book('2026-11-17 10:00');
        $this->rejects(fn () => $this->book('2026-11-17 10:00'), self::BUSY);
        $this->rejects(fn () => $this->book('2026-11-17 10:00', null, $this->doc, self::NOW, 'primeira', null, (int) $this->makePatient()['id']), self::BUSY);
        $this->assertSame(1, $this->total());

        Container::appointments()->cancel($id, 'Família pediu', $this->sec, self::NOW, $this->r());
        $again = $this->book('2026-11-17 10:00');
        $this->assertNotSame($id, $again);
        $this->assertSame(1, (int) $this->db->one('SELECT COUNT(*) n FROM appointments WHERE occupies = 1')['n']);
    }

    public function test_unique_index_violation_becomes_busy_message(): void
    {
        // segunda barreira: mesmo que a verificacao de sobreposicao fosse pulada, o indice unico recusa
        $this->book('2026-11-17 10:00');
        $insert = new \ReflectionMethod(Container::appointments(), 'insertBooking');
        $this->rejects(fn () => $insert->invoke(Container::appointments(), $this->docId(), $this->patId(), '2026-11-17 10:00', '2026-11-17 10:30:00', 'primeira', null, $this->sec, $this->r(), null), self::BUSY);
        $this->assertSame(1, $this->total());
    }

    public function test_overlap_after_duration_change_is_refused(): void
    {
        $old = $this->book('2026-11-17 08:00');
        Container::schedules()->saveWeek($this->docId(), AgendaStatus::CLINIC_DEFAULT, 45, null, $this->docId(), $this->r());
        // grade nova: 07:30, 08:15, 09:00 ...
        $this->rejects(fn () => $this->book('2026-11-17 08:00'), self::CLOSED);
        $this->rejects(fn () => $this->book('2026-11-17 07:30'), self::BUSY);
        $this->rejects(fn () => $this->book('2026-11-17 08:15'), self::BUSY);
        $new = $this->book('2026-11-17 09:00');
        $this->assertSame('2026-11-17 09:45:00', $this->row($new)['ends_at']);
        $this->assertSame('2026-11-17 08:30:00', $this->row($old)['ends_at']);

        $v = $this->view('2026-11-17');
        $this->assertArrayNotHasKey('07:30', $v);
        $this->assertArrayNotHasKey('08:15', $v);
        $this->assertSame('marcado', $v['08:00'][0]['state']);
        $this->assertSame('08:30', $v['08:00'][0]['end']);
        $this->assertSame(['fora_do_horario'], $v['08:00'][0]['flags']);
        $this->assertSame($old, (int) $v['08:00'][0]['appointment']['id']);
        $this->assertSame('marcado', $v['09:00'][0]['state']);
        $this->assertSame([], $v['09:00'][0]['flags']);
        $this->assertSame('livre', $v['09:45'][0]['state']);
        $this->assertNull($v['09:45'][0]['appointment']);
    }

    public function test_closure_over_booked_appointment_keeps_it(): void
    {
        $id = $this->book('2026-11-17 10:00');
        Container::schedules()->addClosure($this->docId(), '2026-11-17', '2026-11-17', '09:00', '12:00', 'Congresso', $this->docId(), $this->r(), self::TODAY);

        $v = $this->view('2026-11-17');
        $this->assertCount(1, $v['10:00']);
        $this->assertSame('marcado', $v['10:00'][0]['state']);
        $this->assertSame(['em_fechamento'], $v['10:00'][0]['flags']);
        $this->assertArrayNotHasKey('10:30', $v);
        $this->assertArrayNotHasKey('09:00', $v);
        $this->assertSame('livre', $v['08:30'][0]['state']);
        $this->rejects(fn () => $this->book('2026-11-17 10:00', null, null, self::NOW, 'primeira', null, (int) $this->makePatient()['id']), self::CLOSED);

        Container::appointments()->cancel($id, 'Congresso', $this->sec, self::NOW, $this->r());
        $v = $this->view('2026-11-17');
        $this->assertCount(1, $v['10:00']);
        $this->assertSame(['riscada', 'em_fechamento'], $v['10:00'][0]['flags']);
        $this->assertSame('cancelada', $v['10:00'][0]['appointment']['status']);
        $this->rejects(fn () => $this->book('2026-11-17 10:00'), self::CLOSED);
    }

    public function test_day_view_states(): void
    {
        $id = $this->book('2026-11-16 10:00');
        $v = $this->view(self::TODAY);
        $this->assertSame('passado', $v['07:30'][0]['state']);
        $this->assertSame('passado', $v['09:00'][0]['state']);
        $this->assertSame('livre', $v['09:30'][0]['state']);
        $this->assertSame('marcado', $v['10:00'][0]['state']);
        $this->assertSame($id, (int) $v['10:00'][0]['appointment']['id']);
        $this->assertSame('Ana Paciente', $v['10:00'][0]['appointment']['patient_name']);
        $this->assertArrayNotHasKey('11:30', $v);
        $this->assertSame(23, array_sum(array_map('count', $v)));
        $starts = array_keys($v);
        $sorted = $starts;
        sort($sorted);
        $this->assertSame($sorted, $starts);

        // cancelada aparece riscada e o horario volta a ficar livre
        Container::appointments()->cancel($id, 'Desistiu', $this->sec, self::NOW, $this->r());
        $v = $this->view(self::TODAY);
        $this->assertCount(2, $v['10:00']);
        $this->assertSame(['riscada'], $v['10:00'][0]['flags']);
        $this->assertSame('livre', $v['10:00'][1]['state']);
    }

    public function test_status_transitions(): void
    {
        $repo = Container::appointments();
        $r = $this->r();
        $sec = $this->sec;
        $id = $this->book('2026-11-17 10:00');
        $st = fn (): string => (string) $this->row($id)['status'];

        // vespera
        $this->rejects(fn () => $repo->setStatus($id, 'realizada', $sec, self::NOW, $r), self::NOT_ALLOWED);
        $this->rejects(fn () => $repo->setStatus($id, 'faltou', $sec, self::NOW, $r), self::NOT_ALLOWED);
        $this->rejects(fn () => $repo->setStatus($id, 'agendada', $sec, self::NOW, $r), self::NOT_ALLOWED);
        $this->rejects(fn () => $repo->setStatus($id, 'cancelada', $sec, self::NOW, $r), self::NOT_ALLOWED);
        $this->rejects(fn () => $repo->setStatus($id, 'remarcada', $sec, self::NOW, $r), self::NOT_ALLOWED);
        $this->rejects(fn () => $repo->setStatus($id, 'reservada', $sec, self::NOW, $r), self::NOT_ALLOWED);
        $this->rejects(fn () => $repo->setStatus($id, 'xyz', $sec, self::NOW, $r), self::NOT_ALLOWED);
        $this->rejects(fn () => $repo->setStatus($id, 'confirmada', $this->makeUser('medico'), self::NOW, $r), 'Você não pode mudar esta consulta.');
        $this->rejects(fn () => $repo->setStatus(999999, 'confirmada', $sec, self::NOW, $r), 'Consulta não encontrada.');
        $this->assertSame('agendada', $st());
        $this->assertSame(0, $this->audits('status'));

        $repo->setStatus($id, 'confirmada', $sec, self::NOW, $r);
        $this->assertSame('confirmada', $st());
        $this->assertSame('2026-11-16 09:00:00', $this->row($id)['status_changed_at']);
        $this->assertSame((int) $sec['id'], (int) $this->row($id)['updated_by']);
        $audit = $this->db->one("SELECT * FROM audit_log WHERE action = 'status' AND entity = 'appointments'");
        $this->assertSame($id, (int) $audit['entity_id']);
        $this->assertSame($this->patId(), (int) $audit['patient_id']);
        $this->assertSame('agendada', json_decode((string) $audit['before_json'], true)['status']);
        $this->assertSame('confirmada', json_decode((string) $audit['after_json'], true)['status']);
        $this->rejects(fn () => $repo->setStatus($id, 'confirmada', $sec, self::NOW, $r), self::NOT_ALLOWED);
        $this->rejects(fn () => $repo->setStatus($id, 'agendada', $sec, self::NOW, $r), self::NOT_ALLOWED);

        // no dia
        $day = '2026-11-17 08:00';
        $repo->setStatus($id, 'realizada', $this->doc, $day, $r);
        $this->assertSame('realizada', $st());
        $this->rejects(fn () => $repo->setStatus($id, 'faltou', $sec, $day, $r), self::NOT_ALLOWED);
        $this->rejects(fn () => $repo->setStatus($id, 'confirmada', $sec, $day, $r), self::NOT_ALLOWED);
        $repo->setStatus($id, 'agendada', $sec, $day, $r);
        $this->assertSame('agendada', $st());
        $repo->setStatus($id, 'faltou', $sec, $day, $r);
        $this->assertSame('faltou', $st());
        $this->assertSame(4, $this->audits('status'));

        // dia seguinte: nao desfaz mais
        $after = '2026-11-18 08:00';
        $this->rejects(fn () => $repo->setStatus($id, 'agendada', $sec, $after, $r), self::NOT_ALLOWED);
        $this->rejects(fn () => $repo->setStatus($id, 'realizada', $sec, $after, $r), self::NOT_ALLOWED);
        $this->assertSame('faltou', $st());

        // confirmar so antes do fim; realizada depois do dia continua possivel
        $b = $this->book('2026-11-17 11:00');
        $repo->setStatus($b, 'confirmada', $sec, '2026-11-17 11:29', $r);
        $c = $this->book('2026-11-17 10:30');
        $this->rejects(fn () => $repo->setStatus($c, 'confirmada', $sec, '2026-11-17 11:00', $r), self::NOT_ALLOWED);
        $repo->setStatus($c, 'realizada', $sec, $after, $r);
        $this->assertSame('realizada', (string) $this->row($c)['status']);
        $repo->setStatus($b, 'faltou', $sec, $after, $r);
        $this->assertSame('faltou', (string) $this->row($b)['status']);

        // cancelada nao volta
        $d = $this->book('2026-11-17 14:00');
        $repo->cancel($d, 'Família pediu', $sec, self::NOW, $r);
        foreach (['agendada', 'confirmada', 'realizada', 'faltou'] as $to) {
            $this->rejects(fn () => $repo->setStatus($d, $to, $sec, '2026-11-17 14:00', $r), self::NOT_ALLOWED);
        }
    }

    public function test_cancel_and_reschedule(): void
    {
        $repo = Container::appointments();
        $r = $this->r();
        $id = $this->book('2026-11-17 10:00', null, null, self::NOW, 'retorno', 'pagar na recepção');

        foreach (['', '   ', str_repeat('m', 256)] as $bad) {
            $this->rejects(fn () => $repo->cancel($id, $bad, $this->sec, self::NOW, $r), 'Informe o motivo do cancelamento.');
        }
        $this->rejects(fn () => $repo->cancel($id, 'x', $this->makeUser('medico'), self::NOW, $r), 'Você não pode mudar esta consulta.');
        $this->assertSame('agendada', $this->row($id)['status']);
        $this->assertSame(0, $this->audits('cancel'));

        // remarcar para nutricionista sem liberacao: recusa e nada muda
        $before = $this->total();
        $this->rejects(fn () => $repo->reschedule($id, $this->nutriId(), '2026-11-18 10:00', $this->sec, self::NOW, $r), 'Peça à médica para liberar este paciente para a nutricionista.');
        $this->rejects(fn () => $repo->reschedule($id, $this->docId(), '2026-11-16 08:00', $this->sec, self::NOW, $r), 'Escolha um horário a partir de agora.');
        $this->rejects(fn () => $repo->reschedule($id, $this->nutriId(), '2026-11-18 10:00', $this->nutri, self::NOW, $r), 'Você não pode mudar esta consulta.');
        $this->assertSame($before, $this->total());
        $this->assertSame('agendada', $this->row($id)['status']);
        $this->assertNull($this->row($id)['rescheduled_to_id']);

        $this->db->insert('professional_access', ['patient_id' => $this->patId(), 'professional_id' => $this->nutriId(), 'granted_by' => $this->doc['id']]);
        $new = $repo->reschedule($id, $this->nutriId(), '2026-11-18 10:00', $this->sec, self::NOW, $r);
        $old = $this->row($id);
        $row = $this->row($new);
        $this->assertSame('remarcada', $old['status']);
        $this->assertSame($new, (int) $old['rescheduled_to_id']);
        $this->assertSame($id, (int) $row['rescheduled_from_id']);
        $this->assertSame($this->nutriId(), (int) $row['professional_id']);
        $this->assertSame($this->patId(), (int) $row['patient_id']);
        $this->assertSame('retorno', $row['kind']);
        $this->assertSame('pagar na recepção', $row['note']);
        $this->assertSame('agendada', $row['status']);
        $this->assertSame('2026-11-18 10:30:00', $row['ends_at']);
        $this->assertSame(1, $this->audits('reschedule'));
        $v = $this->view('2026-11-17');
        $this->assertSame(['riscada'], $v['10:00'][0]['flags']);
        $this->assertSame('livre', $v['10:00'][1]['state']);
        $this->rejects(fn () => $repo->reschedule($id, $this->docId(), '2026-11-17 11:00', $this->sec, self::NOW, $r), 'Esta consulta não pode ser remarcada.');
        $this->rejects(fn () => $repo->cancel($id, 'x', $this->sec, self::NOW, $r), 'Esta consulta não pode ser cancelada.');

        // remarcar na mesma agenda para um horario que encosta na antiga (duracao mudou)
        $a = $this->book('2026-11-17 08:00');
        Container::schedules()->saveWeek($this->docId(), AgendaStatus::CLINIC_DEFAULT, 45, null, $this->docId(), $r);
        $moved = $repo->reschedule($a, $this->docId(), '2026-11-17 08:15', $this->doc, self::NOW, $r);
        $this->assertSame('2026-11-17 09:00:00', $this->row($moved)['ends_at']);
        $this->assertSame('remarcada', $this->row($a)['status']);

        // cancelar
        $repo->cancel($new, '  Família pediu  ', $this->nutri, self::NOW, $r);
        $this->assertSame('cancelada', $this->row($new)['status']);
        $this->assertSame('Família pediu', $this->row($new)['cancel_reason']);
        $this->assertSame(1, $this->audits('cancel'));

        // profissional desativada: consultas antigas ainda podem ser canceladas e remarcadas
        $b = $this->book('2026-11-19 09:45');
        $c = $this->book('2026-11-19 10:30');
        $this->db->run('UPDATE users SET active = 0 WHERE id = ?', [$this->docId()]);
        $this->rejects(fn () => $this->book('2026-11-19 11:00'), 'Você não pode marcar nesta agenda.');
        $repo->cancel($b, 'Profissional saiu', $this->sec, self::NOW, $r);
        $this->assertSame('cancelada', $this->row($b)['status']);
        $other = $this->makeUser('medico');
        Container::schedules()->applyClinicDefault((int) $other['id'], (int) $other['id'], $r);
        $d = $repo->reschedule($c, (int) $other['id'], '2026-11-19 10:30', $this->sec, self::NOW, $r);
        $this->assertSame('remarcada', $this->row($c)['status']);
        $this->assertSame((int) $other['id'], (int) $this->row($d)['professional_id']);
        $this->rejects(fn () => $repo->reschedule($d, $this->docId(), '2026-11-19 11:00', $this->sec, self::NOW, $r), 'Você não pode marcar nesta agenda.');
    }

    public function test_suggested_kind_and_for_patient(): void
    {
        $repo = Container::appointments();
        $this->assertSame('primeira', $repo->suggestedKind($this->docId(), $this->patId()));
        $this->raw($this->docId(), '2026-10-01 08:00:00', '2026-10-01 08:30:00', 'faltou');
        $this->assertSame('primeira', $repo->suggestedKind($this->docId(), $this->patId()));
        $this->raw($this->docId(), '2026-10-02 08:00:00', '2026-10-02 08:30:00', 'realizada');
        $this->assertSame('retorno', $repo->suggestedKind($this->docId(), $this->patId()));
        $this->assertSame('primeira', $repo->suggestedKind($this->nutriId(), $this->patId()));
        $this->assertSame('primeira', $repo->suggestedKind($this->docId(), (int) $this->makePatient()['id']));

        $this->raw($this->docId(), '2026-10-03 08:00:00', '2026-10-03 08:30:00', 'cancelada');
        $this->raw($this->docId(), '2026-10-04 08:00:00', '2026-10-04 08:30:00', 'remarcada');
        $this->raw($this->nutriId(), '2026-10-05 08:00:00', '2026-10-05 08:30:00', 'realizada');
        $this->raw($this->docId(), '2026-11-16 08:00:00', '2026-11-16 08:30:00', 'realizada');
        $this->raw($this->docId(), '2026-09-01 08:00:00', '2026-09-01 08:30:00', 'realizada');
        $f1 = $this->raw($this->docId(), '2026-11-20 08:00:00', '2026-11-20 08:30:00', 'agendada');
        $f2 = $this->raw($this->nutriId(), '2026-11-18 08:00:00', '2026-11-18 08:30:00', 'confirmada');
        $this->raw($this->docId(), '2026-11-19 08:00:00', '2026-11-19 08:30:00', 'cancelada');
        $this->raw($this->docId(), '2026-11-21 08:00:00', '2026-11-21 08:30:00', 'agendada', (int) $this->makePatient()['id']);

        $all = $repo->forPatient($this->patId(), null, self::NOW);
        $this->assertSame([$f2, $f1], array_map(fn (array $x): int => (int) $x['id'], $all['upcoming']));
        $this->assertSame(
            ['2026-11-16 08:00:00', '2026-10-05 08:00:00', '2026-10-04 08:00:00', '2026-10-03 08:00:00', '2026-10-02 08:00:00'],
            array_column($all['past'], 'starts_at'),
        );
        $this->assertSame('Nutri Teste', $all['upcoming'][0]['professional_name']);

        $mine = $repo->forPatient($this->patId(), $this->nutriId(), self::NOW);
        $this->assertSame([$f2], array_map(fn (array $x): int => (int) $x['id'], $mine['upcoming']));
        $this->assertSame(['2026-10-05 08:00:00'], array_column($mine['past'], 'starts_at'));

        $range = $repo->forRange($this->docId(), '2026-10-02', '2026-10-04');
        $this->assertSame(['2026-10-02 08:00:00', '2026-10-03 08:00:00', '2026-10-04 08:00:00'], array_column($range, 'starts_at'));
        $this->assertSame(['2026-11-16 08:00:00'], array_column($repo->forDay($this->docId(), '2026-11-16'), 'starts_at'));
    }

    public function test_remindable_only_agendada_and_confirmada(): void
    {
        $other = $this->makePatient(['full_name' => 'Bruno Paciente']);
        $ids = [];
        foreach (AgendaStatus::STATUSES as $i => $status) {
            $h = sprintf('%02d', 8 + $i);
            $ids[$status] = $this->raw($this->docId(), "2026-11-17 {$h}:00:00", "2026-11-17 {$h}:30:00", $status);
        }
        $n2 = $this->raw($this->nutriId(), '2026-11-17 07:30:00', '2026-11-17 08:00:00', 'agendada', (int) $other['id']);
        $n1 = $this->raw($this->nutriId(), '2026-11-17 07:00:00', '2026-11-17 07:30:00', 'confirmada', (int) $other['id']);
        $this->raw($this->docId(), '2026-11-18 08:00:00', '2026-11-18 08:30:00', 'agendada');

        $list = Container::appointments()->remindable('2026-11-17');
        $this->assertSame(
            [$ids['agendada'], $ids['confirmada'], $n1, $n2],
            array_map(fn (array $x): int => (int) $x['id'], $list),
        );
        $this->assertSame('Ana Paciente', $list[0]['patient_name']);
        $this->assertSame('Dra. Teste', $list[0]['professional_name']);
        $this->assertSame('Bruno Paciente', $list[2]['patient_name']);
        $this->assertSame('nutricionista', $list[2]['professional_role']);
        $this->assertSame([], Container::appointments()->remindable('2026-11-19'));
    }
}
