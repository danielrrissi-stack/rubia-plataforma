<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Core\App;
use App\Core\Container;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use Tests\DbTestCase;

final class AgendaPagesTest extends DbTestCase
{
    private array $admin;
    private array $doc;
    private array $nutri;
    private array $sec;
    private array $patient;
    private array $other;
    private string $mon;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeUser('medico', ['name' => 'Dra. Admin', 'is_admin' => 1, 'totp_enabled' => 1]);
        $this->doc = $this->makeUser('medico', ['name' => 'Dra. Comum', 'totp_enabled' => 1]);
        $this->nutri = $this->makeUser('nutricionista', ['name' => 'Nutri Teste', 'totp_enabled' => 1]);
        $this->sec = $this->makeUser('secretaria', ['name' => 'Secretaria Teste', 'totp_enabled' => 1]);
        $this->patient = $this->makePatient(['full_name' => 'Ana Paciente', 'birth_date' => '1990-01-10', 'responsible_professional_id' => $this->doc['id'], 'created_by' => $this->doc['id']]);
        $this->other = $this->makePatient(['full_name' => 'Bruno Outro', 'birth_date' => '1985-02-10', 'responsible_professional_id' => $this->doc['id'], 'created_by' => $this->doc['id']]);
        foreach ([$this->admin, $this->doc, $this->nutri] as $p) {
            Container::schedules()->applyClinicDefault((int) $p['id'], (int) $p['id'], new Request('POST', '/'));
        }
        Container::accessGrants()->grant((int) $this->patient['id'], (int) $this->nutri['id'], (int) $this->doc['id']);
        $this->mon = date('Y-m-d', strtotime('next monday'));
    }

    private function get(string $path, array $query = []): Response
    {
        return App::create()->handle(new Request('GET', $path, $query));
    }

    private function post(string $path, array $data): Response
    {
        $data['_csrf'] = Csrf::token();
        return App::create()->handle(new Request('POST', $path, [], $data, [], ['REMOTE_ADDR' => '127.0.0.1']));
    }

    private function loginAs(array $u): void
    {
        Session::reset();
        Container::auth()->completeLogin((int) $u['id'], new Request('POST', '/login'));
    }

    private function id(array $u): int
    {
        return (int) $u['id'];
    }

    private function bookDirect(array $prof, array $patient, string $startsAt): int
    {
        return Container::appointments()->book($this->id($prof), $this->id($patient), $startsAt, 'primeira', null, $this->sec, date('Y-m-d H:i'), new Request('POST', '/'));
    }

    private function apptCount(): int
    {
        return (int) $this->db->one('SELECT COUNT(*) n FROM appointments')['n'];
    }

    public function test_secretary_books_on_doctor_agenda(): void
    {
        $this->loginAs($this->sec);
        $res = $this->get('/agenda', ['prof' => (string) $this->id($this->doc), 'data' => $this->mon]);
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('<select id="agenda-prof"', $res->body, 'secretaria tem seletor de profissional');
        $this->assertStringContainsString('Dra. Comum', $res->body);
        $this->assertStringContainsString('/agenda/marcar?prof=' . $this->id($this->doc) . '&amp;inicio=' . rawurlencode($this->mon . ' 08:00'), $res->body);

        $form = $this->get('/agenda/marcar', ['prof' => (string) $this->id($this->doc), 'inicio' => $this->mon . ' 08:00', 'q' => 'Ana']);
        $this->assertSame(200, $form->status);
        $this->assertStringContainsString('Ana Paciente', $form->body);
        $this->assertStringNotContainsString('Bruno Outro', $form->body);

        $chosen = $this->get('/agenda/marcar', ['prof' => (string) $this->id($this->doc), 'inicio' => $this->mon . ' 08:00', 'paciente' => (string) $this->id($this->patient)]);
        $this->assertSame(200, $chosen->status);
        $this->assertMatchesRegularExpression('/<option value="primeira" selected>/', $chosen->body);

        $post = $this->post('/agenda/marcar', [
            'prof' => (string) $this->id($this->doc), 'inicio' => $this->mon . ' 08:00', 'patient_id' => (string) $this->id($this->patient),
            'kind' => 'primeira', 'note' => 'trazer exames',
        ]);
        $this->assertSame(302, $post->status);
        $this->assertSame('/agenda?prof=' . $this->id($this->doc) . '&data=' . $this->mon, $post->headers['Location']);
        $this->assertSame(1, $this->apptCount());

        $day = $this->get('/agenda', ['prof' => (string) $this->id($this->doc), 'data' => $this->mon]);
        $this->assertStringContainsString('Ana Paciente', $day->body);
        $this->assertStringContainsString('Primeira consulta', $day->body);
        $this->assertStringContainsString('Agendada', $day->body);

        $week = $this->get('/agenda', ['prof' => (string) $this->id($this->doc), 'data' => $this->mon, 'vista' => 'semana']);
        $this->assertSame(200, $week->status);
        $this->assertStringContainsString('Ana Paciente', $week->body);
    }

    public function test_professional_sees_only_own_agenda(): void
    {
        $this->loginAs($this->doc);
        $this->assertSame(403, $this->get('/agenda', ['prof' => (string) $this->id($this->admin)])->status);
        $denied = $this->db->one("SELECT after_json FROM audit_log WHERE action = 'denied' ORDER BY id DESC LIMIT 1");
        $this->assertNotNull($denied);
        $this->assertStringContainsString('agenda', (string) $denied['after_json']);
        $own = $this->get('/agenda');
        $this->assertSame(200, $own->status);
        $this->assertStringNotContainsString('<select id="agenda-prof"', $own->body);
        $this->assertStringContainsString('Dra. Comum', $own->body);

        $this->loginAs($this->nutri);
        $mine = $this->get('/agenda', ['data' => $this->mon]);
        $this->assertSame(200, $mine->status);
        $this->assertStringNotContainsString('<select id="agenda-prof"', $mine->body);
        $this->assertSame(403, $this->get('/agenda', ['prof' => (string) $this->id($this->doc)])->status);
        $this->assertSame(403, $this->get('/agenda/marcar', ['prof' => (string) $this->id($this->doc), 'inicio' => $this->mon . ' 08:00'])->status);
    }

    public function test_book_errors_show_422(): void
    {
        $this->loginAs($this->sec);
        $base = ['prof' => (string) $this->id($this->doc), 'kind' => 'primeira', 'note' => ''];
        $ok = $this->post('/agenda/marcar', $base + ['inicio' => $this->mon . ' 08:00', 'patient_id' => (string) $this->id($this->patient)]);
        $this->assertSame(302, $ok->status);

        $busy = $this->post('/agenda/marcar', $base + ['inicio' => $this->mon . ' 08:00', 'patient_id' => (string) $this->id($this->other)]);
        $this->assertSame(422, $busy->status);
        $this->assertStringContainsString('Este horário não está mais livre.', $busy->body);

        $tue = (new \DateTimeImmutable($this->mon))->modify('+1 day')->format('Y-m-d');
        Container::schedules()->addClosure($this->id($this->doc), $tue, $tue, null, null, 'Congresso', $this->id($this->doc), new Request('POST', '/'), date('Y-m-d'));
        $closed = $this->post('/agenda/marcar', $base + ['inicio' => $tue . ' 08:00', 'patient_id' => (string) $this->id($this->other)]);
        $this->assertSame(422, $closed->status);
        $this->assertStringContainsString('Este horário não está aberto na agenda.', $closed->body);

        $lastMon = date('Y-m-d', strtotime('last monday'));
        $past = $this->post('/agenda/marcar', $base + ['inicio' => $lastMon . ' 08:00', 'patient_id' => (string) $this->id($this->other)]);
        $this->assertSame(422, $past->status);
        $this->assertStringContainsString('Escolha um horário a partir de agora.', $past->body);

        $this->assertSame(1, $this->apptCount());
    }

    public function test_status_cancel_reschedule_flow(): void
    {
        $id = $this->bookDirect($this->doc, $this->patient, $this->mon . ' 08:00');
        $this->loginAs($this->sec);
        $page = $this->get('/agenda/consultas/' . $id);
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('Ana Paciente', $page->body);
        $this->assertStringContainsString('Confirmar', $page->body);
        $this->assertStringNotContainsString('Registrar evolução', $page->body);

        $this->assertSame(302, $this->post('/agenda/consultas/' . $id . '/situacao', ['situacao' => 'confirmada'])->status);
        $this->assertSame('confirmada', Container::appointments()->find($id)['status']);
        $this->post('/agenda/consultas/' . $id . '/situacao', ['situacao' => 'realizada']);
        $this->assertSame('confirmada', Container::appointments()->find($id)['status'], 'futura nao pode ser realizada');

        $form = $this->get('/agenda/consultas/' . $id . '/remarcar', ['prof' => (string) $this->id($this->doc), 'data' => $this->mon]);
        $this->assertSame(200, $form->status);
        $this->assertStringContainsString('09:00', $form->body);
        $moved = $this->post('/agenda/consultas/' . $id . '/remarcar', ['prof' => (string) $this->id($this->doc), 'inicio' => $this->mon . ' 09:00']);
        $this->assertSame(302, $moved->status);
        $old = Container::appointments()->find($id);
        $this->assertSame('remarcada', $old['status']);
        $newId = (int) $old['rescheduled_to_id'];
        $this->assertSame('/agenda/consultas/' . $newId, $moved->headers['Location']);

        $bad = $this->post('/agenda/consultas/' . $newId . '/remarcar', ['prof' => (string) $this->id($this->doc), 'inicio' => $this->mon . ' 07:45']);
        $this->assertSame(422, $bad->status);

        $this->post('/agenda/consultas/' . $newId . '/cancelar', ['motivo' => '']);
        $this->assertSame('agendada', Container::appointments()->find($newId)['status']);
        $this->assertSame(302, $this->post('/agenda/consultas/' . $newId . '/cancelar', ['motivo' => 'Família pediu'])->status);
        $this->assertSame('cancelada', Container::appointments()->find($newId)['status']);

        // agenda do dia: as duas riscadas e o horario das 08:00 livre de novo
        $day = $this->get('/agenda', ['prof' => (string) $this->id($this->doc), 'data' => $this->mon]);
        $this->assertStringContainsString('Cancelada', $day->body);
        $this->assertStringContainsString('Remarcada', $day->body);
        $this->assertStringContainsString('inicio=' . rawurlencode($this->mon . ' 08:00'), $day->body);

        // consulta de hoje (ja passada) marcada como realizada pela medica: atalho de evolucao
        $today = date('Y-m-d');
        $todayId = $this->db->insert('appointments', [
            'professional_id' => $this->id($this->doc), 'patient_id' => $this->id($this->patient),
            'starts_at' => $today . ' 00:00:00', 'ends_at' => $today . ' 00:30:00', 'kind' => 'retorno', 'status' => 'agendada',
            'created_by' => $this->id($this->sec),
        ]);
        $this->loginAs($this->doc);
        $this->assertSame(302, $this->post('/agenda/consultas/' . $todayId . '/situacao', ['situacao' => 'realizada'])->status);
        $this->assertSame('realizada', Container::appointments()->find($todayId)['status']);
        $done = $this->get('/agenda/consultas/' . $todayId);
        $this->assertStringContainsString('Registrar evolução', $done->body);
        $this->assertStringContainsString('/pacientes/' . $this->id($this->patient) . '/prontuario/evolucao', $done->body);
        $this->assertStringContainsString('Desfazer', $done->body);
    }

    public function test_nutritionist_unreleased_patient_hidden(): void
    {
        $this->loginAs($this->sec);
        $refused = $this->post('/agenda/marcar', [
            'prof' => (string) $this->id($this->nutri), 'inicio' => $this->mon . ' 08:00', 'patient_id' => (string) $this->id($this->other), 'kind' => 'primeira',
        ]);
        $this->assertSame(422, $refused->status);
        $this->assertStringContainsString('Peça à médica para liberar este paciente para a nutricionista.', $refused->body);
        $search = $this->get('/agenda/marcar', ['prof' => (string) $this->id($this->nutri), 'inicio' => $this->mon . ' 08:00', 'q' => 'n']);
        $this->assertStringContainsString('Ana Paciente', $search->body);
        $this->assertStringNotContainsString('Bruno Outro', $search->body);

        $id = $this->bookDirect($this->nutri, $this->patient, $this->mon . ' 08:00');
        $docAppt = $this->bookDirect($this->doc, $this->patient, $this->mon . ' 08:00');
        $this->db->run('UPDATE professional_access SET revoked_at = NOW() WHERE professional_id = ?', [$this->id($this->nutri)]);

        $this->loginAs($this->nutri);
        $day = $this->get('/agenda', ['data' => $this->mon]);
        $this->assertSame(200, $day->status);
        $this->assertStringContainsString('Paciente sem liberação', $day->body);
        $this->assertStringNotContainsString('Ana Paciente', $day->body);
        $this->assertStringNotContainsString('/agenda/consultas/' . $id, $day->body);
        $this->assertStringNotContainsString('/pacientes/' . $this->id($this->patient), $day->body);
        $week = $this->get('/agenda', ['data' => $this->mon, 'vista' => 'semana']);
        $this->assertStringContainsString('Paciente sem liberação', $week->body);
        $this->assertStringNotContainsString('Ana Paciente', $week->body);

        $this->assertSame(404, $this->get('/agenda/consultas/' . $id)->status);
        $this->assertSame(404, $this->get('/agenda/consultas/' . $id . '/remarcar')->status);
        $this->assertSame(404, $this->post('/agenda/consultas/' . $id . '/situacao', ['situacao' => 'confirmada'])->status);
        $this->assertSame(404, $this->post('/agenda/consultas/' . $id . '/cancelar', ['motivo' => 'x'])->status);
        $this->assertSame('agendada', Container::appointments()->find($id)['status']);
        $this->assertSame(404, $this->get('/agenda/consultas/' . $docAppt)->status, 'consulta de outra agenda');

        // secretaria ainda ve o nome e pode remarcar
        $this->loginAs($this->sec);
        $secDay = $this->get('/agenda', ['prof' => (string) $this->id($this->nutri), 'data' => $this->mon]);
        $this->assertStringContainsString('Ana Paciente', $secDay->body);
        $this->assertSame(200, $this->get('/agenda/consultas/' . $id)->status);
    }

    public function test_family_and_child_get_403_or_redirect(): void
    {
        $resp = $this->makeUser('responsavel');
        $this->db->insert('guardian_links', ['patient_id' => $this->id($this->patient), 'user_id' => $resp['id'], 'relationship' => 'mãe', 'created_by' => $this->doc['id']]);
        $this->loginAs($resp);
        $this->assertSame(403, $this->get('/agenda')->status);
        $this->assertSame(403, $this->get('/agenda/horarios')->status);
        $this->assertSame(403, $this->post('/agenda/marcar', ['prof' => (string) $this->id($this->doc), 'inicio' => $this->mon . ' 08:00', 'patient_id' => (string) $this->id($this->patient), 'kind' => 'primeira'])->status);
        $this->assertSame(0, $this->apptCount());
        $this->assertNotNull($this->db->one("SELECT id FROM audit_log WHERE action = 'denied' AND user_id = ? AND after_json LIKE '%agenda%'", [$resp['id']]));

        $kid = $this->makeUser('crianca', ['email' => null, 'username' => 'kid.' . bin2hex(random_bytes(3))]);
        $this->makePatient(['full_name' => 'Crianca', 'birth_date' => '2016-01-01', 'child_user_id' => $kid['id']]);
        $this->loginAs($kid);
        $this->assertSame(302, $this->get('/agenda')->status);
        $this->assertSame(302, $this->get('/agenda/horarios')->status);
    }
}
