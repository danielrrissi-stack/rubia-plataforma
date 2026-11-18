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

final class SchedulePagesTest extends DbTestCase
{
    private array $admin;
    private array $doc;
    private array $nutri;
    private array $sec;
    private string $mon;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeUser('medico', ['name' => 'Dra. Admin', 'is_admin' => 1, 'totp_enabled' => 1]);
        $this->doc = $this->makeUser('medico', ['name' => 'Dra. Comum', 'totp_enabled' => 1]);
        $this->nutri = $this->makeUser('nutricionista', ['name' => 'Nutri Teste', 'totp_enabled' => 1]);
        $this->sec = $this->makeUser('secretaria', ['totp_enabled' => 1]);
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

    private function week(array $over = []): array
    {
        return $over + [
            'slot_minutes' => '45',
            'address' => 'Rua das Flores, 10',
            'ranges' => [
                ['weekday' => '1', 'start_time' => '08:00', 'end_time' => '12:00'],
                ['weekday' => '3', 'start_time' => '13:00', 'end_time' => '18:00'],
                ['weekday' => '', 'start_time' => '', 'end_time' => ''],
            ],
        ];
    }

    public function test_apply_default_and_edit_week(): void
    {
        $this->loginAs($this->doc);
        $page = $this->get('/agenda/horarios');
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('Usar horário padrão da clínica', $page->body);
        $this->assertStringContainsString('Dra. Comum', $page->body);

        $this->assertSame(302, $this->post('/agenda/horarios/padrao', ['prof' => (string) $this->id($this->doc)])->status);
        $this->assertCount(11, Container::schedules()->ranges($this->id($this->doc)));
        $after = $this->get('/agenda/horarios');
        $this->assertStringNotContainsString('Usar horário padrão da clínica', $after->body);
        $this->assertStringContainsString('12:30', $after->body);

        $saved = $this->post('/agenda/horarios', $this->week(['prof' => (string) $this->id($this->doc)]));
        $this->assertSame(302, $saved->status);
        $this->assertSame('/agenda/horarios?prof=' . $this->id($this->doc), $saved->headers['Location']);
        $this->assertCount(2, Container::schedules()->ranges($this->id($this->doc)));
        $this->assertSame(['slot_minutes' => 45, 'address' => 'Rua das Flores, 10'], Container::schedules()->profile($this->id($this->doc)));
        $this->assertNotNull($this->db->one("SELECT id FROM audit_log WHERE action = 'update' AND entity = 'professional_schedules' AND entity_id = ?", [$this->id($this->doc)]));

        $bad = $this->post('/agenda/horarios', $this->week(['prof' => (string) $this->id($this->doc), 'slot_minutes' => '7']));
        $this->assertSame(422, $bad->status);
        $this->assertStringContainsString('A duração da consulta deve ser de 10 a 240 minutos, em múltiplos de 5.', $bad->body);
        $this->assertSame(45, Container::schedules()->profile($this->id($this->doc))['slot_minutes']);

        $extra = $this->post('/agenda/horarios/extra', ['prof' => (string) $this->id($this->doc), 'date' => $this->mon, 'start_time' => '18:00', 'end_time' => '19:00']);
        $this->assertSame(302, $extra->status);
        $this->assertCount(1, Container::schedules()->exceptions($this->id($this->doc), $this->mon, $this->mon));
    }

    public function test_secretary_adds_closure_but_cannot_edit_week(): void
    {
        Container::schedules()->applyClinicDefault($this->id($this->doc), $this->id($this->doc), new Request('POST', '/'));
        $this->loginAs($this->sec);
        $page = $this->get('/agenda/horarios', ['prof' => (string) $this->id($this->doc)]);
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('Novo fechamento', $page->body);
        $this->assertStringNotContainsString('name="slot_minutes"', $page->body);
        $this->assertStringNotContainsString('Usar horário padrão da clínica', $page->body);

        $this->assertSame(403, $this->post('/agenda/horarios', $this->week(['prof' => (string) $this->id($this->doc)]))->status);
        $this->assertSame(403, $this->post('/agenda/horarios/padrao', ['prof' => (string) $this->id($this->nutri)])->status);
        $this->assertCount(11, Container::schedules()->ranges($this->id($this->doc)));

        $closure = $this->post('/agenda/horarios/fechamento', [
            'prof' => (string) $this->id($this->doc), 'start_date' => $this->mon, 'end_date' => $this->mon, 'start_time' => '', 'end_time' => '', 'reason' => 'Congresso',
        ]);
        $this->assertSame(302, $closure->status);
        $list = Container::schedules()->exceptions($this->id($this->doc), $this->mon, $this->mon);
        $this->assertCount(1, $list);
        $listed = $this->get('/agenda/horarios', ['prof' => (string) $this->id($this->doc)]);
        $this->assertStringContainsString('Congresso', $listed->body);

        $bad = $this->post('/agenda/horarios/fechamento', [
            'prof' => (string) $this->id($this->doc), 'start_date' => $this->mon, 'end_date' => $this->mon, 'start_time' => '10:00', 'end_time' => '', 'reason' => '',
        ]);
        $this->assertSame(422, $bad->status);
        $this->assertStringContainsString('Informe o horário inicial e o final', $bad->body);

        $this->assertSame(302, $this->post('/agenda/horarios/excecao/' . (int) $list[0]['id'] . '/remover', [])->status);
        $this->assertCount(0, Container::schedules()->exceptions($this->id($this->doc), $this->mon, $this->mon));
        $this->assertSame(404, $this->post('/agenda/horarios/excecao/' . (int) $list[0]['id'] . '/remover', [])->status);
    }

    public function test_secretary_cannot_add_or_remove_extras(): void
    {
        $doc = $this->id($this->doc);
        Container::schedules()->applyClinicDefault($doc, $doc, new Request('POST', '/'));
        $extra = Container::schedules()->addExtra($doc, $this->mon, '20:00', '21:00', $doc, new Request('POST', '/'), date('Y-m-d'));
        $closure = Container::schedules()->addClosure($doc, $this->mon, $this->mon, '08:00', '09:00', 'Exame', $doc, new Request('POST', '/'), date('Y-m-d'));
        $this->loginAs($this->sec);
        $page = $this->get('/agenda/horarios', ['prof' => (string) $doc]);
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('Horários de Dra. Comum', $page->body);
        $this->assertStringContainsString('Novo fechamento', $page->body);
        $this->assertStringNotContainsString('Novo horário extra', $page->body);
        $this->assertStringNotContainsString('/excecao/' . $extra . '/remover', $page->body);
        $this->assertStringContainsString('/excecao/' . $closure . '/remover', $page->body);

        $this->assertSame(403, $this->post('/agenda/horarios/extra', ['prof' => (string) $doc, 'date' => $this->mon, 'start_time' => '21:00', 'end_time' => '22:00'])->status);
        $this->assertSame(403, $this->post('/agenda/horarios/excecao/' . $extra . '/remover', [])->status);
        $this->assertCount(2, Container::schedules()->exceptions($doc, $this->mon, $this->mon));
        $this->assertSame(302, $this->post('/agenda/horarios/excecao/' . $closure . '/remover', [])->status);
        $this->assertSame([$extra], array_map(fn (array $x): int => (int) $x['id'], Container::schedules()->exceptions($doc, $this->mon, $this->mon)));
    }

    public function test_professional_sees_meus_horarios(): void
    {
        $this->loginAs($this->doc);
        $agenda = $this->get('/agenda');
        $this->assertStringContainsString('>Meus horários</a>', $agenda->body);
        $page = $this->get('/agenda/horarios');
        $this->assertStringContainsString('<h1>Meus horários</h1>', $page->body);
        $this->assertStringContainsString('<title>Meus horários', $page->body);
        $this->loginAs($this->admin);
        $other = $this->get('/agenda/horarios', ['prof' => (string) $this->id($this->nutri)]);
        $this->assertStringContainsString('<h1>Horários de Nutri Teste</h1>', $other->body);
        $this->assertStringContainsString('>Horários de Nutri Teste</a>', $this->get('/agenda', ['prof' => (string) $this->id($this->nutri)])->body);
    }

    public function test_closure_warns_about_booked(): void
    {
        Container::schedules()->applyClinicDefault($this->id($this->doc), $this->id($this->doc), new Request('POST', '/'));
        $p1 = $this->makePatient(['full_name' => 'Ana Paciente']);
        $p2 = $this->makePatient(['full_name' => 'Bia Paciente']);
        foreach ([[$p1, '08:00'], [$p2, '09:00']] as [$p, $hm]) {
            Container::appointments()->book($this->id($this->doc), (int) $p['id'], $this->mon . ' ' . $hm, 'primeira', null, $this->doc, date('Y-m-d H:i'), new Request('POST', '/'));
        }
        $this->loginAs($this->doc);
        $res = $this->post('/agenda/horarios/fechamento', [
            'prof' => (string) $this->id($this->doc), 'start_date' => $this->mon, 'end_date' => $this->mon, 'start_time' => '', 'end_time' => '', 'reason' => 'Férias',
        ]);
        $this->assertSame(302, $res->status);
        $page = $this->get('/agenda/horarios');
        $this->assertStringContainsString('Há 2 consultas marcadas nesse período. Elas continuam na agenda para você remarcar.', $page->body);

        // a agenda do dia mostra as consultas destacadas em periodo fechado
        $day = $this->get('/agenda', ['data' => $this->mon]);
        $this->assertStringContainsString('Ana Paciente', $day->body);
        $this->assertStringContainsString('em período fechado', $day->body);
    }

    public function test_admin_edits_other_professional_week(): void
    {
        $this->loginAs($this->admin);
        $page = $this->get('/agenda/horarios', ['prof' => (string) $this->id($this->nutri)]);
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('name="slot_minutes"', $page->body);
        $this->assertStringContainsString('Nutri Teste', $page->body);
        $this->assertSame(302, $this->post('/agenda/horarios', $this->week(['prof' => (string) $this->id($this->nutri)]))->status);
        $this->assertCount(2, Container::schedules()->ranges($this->id($this->nutri)));

        $this->loginAs($this->doc);
        $this->assertSame(403, $this->get('/agenda/horarios', ['prof' => (string) $this->id($this->nutri)])->status);
        $this->assertSame(403, $this->post('/agenda/horarios', $this->week(['prof' => (string) $this->id($this->nutri)]))->status);
        $this->assertSame(403, $this->post('/agenda/horarios/fechamento', ['prof' => (string) $this->id($this->nutri), 'start_date' => $this->mon, 'end_date' => $this->mon])->status);
        $this->assertCount(2, Container::schedules()->ranges($this->id($this->nutri)));
    }
}
