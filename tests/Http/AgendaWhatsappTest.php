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

final class AgendaWhatsappTest extends DbTestCase
{
    private array $admin;
    private array $doc;
    private array $nutri;
    private array $sec;
    private array $adult;
    private array $child;
    private array $mom;
    private string $mon;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeUser('medico', ['name' => 'Dra. Admin', 'is_admin' => 1, 'totp_enabled' => 1]);
        $this->doc = $this->makeUser('medico', ['name' => 'Dra. Comum', 'totp_enabled' => 1]);
        $this->nutri = $this->makeUser('nutricionista', ['name' => 'Nutri Teste', 'totp_enabled' => 1]);
        $this->sec = $this->makeUser('secretaria', ['name' => 'Secretaria Teste', 'totp_enabled' => 1]);
        $this->adult = $this->makePatient(['full_name' => 'Ana Paciente', 'birth_date' => '1990-01-10', 'phone' => '(11) 98765-4321', 'responsible_professional_id' => $this->doc['id'], 'created_by' => $this->doc['id']]);
        $this->child = $this->makePatient(['full_name' => 'Caio Pequeno', 'birth_date' => '2018-05-05', 'phone' => '', 'responsible_professional_id' => $this->doc['id'], 'created_by' => $this->doc['id']]);
        $this->mom = $this->makeUser('responsavel', ['name' => 'Mãe Teste', 'phone' => '11 3456-7890']);
        Container::guardians()->link((int) $this->child['id'], (int) $this->mom['id'], 'mãe', (int) $this->doc['id']);
        foreach ([$this->doc, $this->nutri] as $p) {
            Container::schedules()->applyClinicDefault((int) $p['id'], (int) $p['id'], new Request('POST', '/'));
        }
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

    private function book(array $prof, array $patient, string $time): int
    {
        return Container::appointments()->book((int) $prof['id'], (int) $patient['id'], $this->mon . ' ' . $time, 'primeira', null, $this->sec, date('Y-m-d H:i'), new Request('POST', '/'));
    }

    public function test_buttons_for_patient_and_guardian(): void
    {
        $a1 = $this->book($this->doc, $this->adult, '08:00');
        $a2 = $this->book($this->doc, $this->child, '09:00');
        $today = date('Y-m-d');
        $text1 = Container::reminderText()->render(Container::appointments()->find($a1), $today);
        $text2 = Container::reminderText()->render(Container::appointments()->find($a2), $today);
        $this->assertStringStartsWith('Olá! Lembrando da consulta de Caio', $text2);

        $this->loginAs($this->sec);
        foreach (['dia', 'semana'] as $vista) {
            $res = $this->get('/agenda', ['prof' => (string) $this->doc['id'], 'data' => $this->mon, 'vista' => $vista]);
            $this->assertSame(200, $res->status);
            $this->assertStringContainsString('href="https://wa.me/5511987654321?text=' . rawurlencode($text1) . '"', $res->body);
            $this->assertStringContainsString('data-text="' . e($text1) . '"', $res->body);
            $this->assertStringContainsString('WhatsApp — paciente', $res->body);
            $this->assertStringContainsString('href="https://wa.me/551134567890?text=' . rawurlencode($text2) . '"', $res->body);
            $this->assertStringContainsString('WhatsApp — Mãe Teste', $res->body);
            $this->assertStringContainsString('target="_blank" rel="noopener"', $res->body);
            $this->assertStringContainsString('/assets/js/agenda-whatsapp.js', $res->body);
        }

        $page = $this->get('/agenda/consultas/' . $a1);
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('class="btn btn-quiet btn-whatsapp"', $page->body);
        $this->assertStringContainsString('data-text="' . e($text1) . '"', $page->body);
        $this->assertStringContainsString('/assets/js/agenda-whatsapp.js', $page->body);
    }

    public function test_invalid_phone_and_cancelled(): void
    {
        $this->db->run('UPDATE patients SET phone = ? WHERE id = ?', ['98765-4321', (int) $this->adult['id']]);
        $a1 = $this->book($this->doc, $this->adult, '08:00');
        $this->loginAs($this->sec);
        $page = $this->get('/agenda/consultas/' . $a1);
        $this->assertStringContainsString('telefone em formato inválido', $page->body);
        $this->assertStringNotContainsString('btn-whatsapp', $page->body);

        // sem telefone nenhum: nada
        $this->db->run('UPDATE users SET phone = NULL WHERE id = ?', [(int) $this->mom['id']]);
        $a2 = $this->book($this->doc, $this->child, '09:00');
        $none = $this->get('/agenda/consultas/' . $a2);
        $this->assertStringNotContainsString('btn-whatsapp', $none->body);
        $this->assertStringNotContainsString('formato inválido', $none->body);

        $this->db->run('UPDATE patients SET phone = ? WHERE id = ?', ['(11) 98765-4321', (int) $this->adult['id']]);
        Container::appointments()->cancel($a1, 'pediu', $this->sec, date('Y-m-d H:i'), new Request('POST', '/'));
        $cancelled = $this->get('/agenda/consultas/' . $a1);
        $this->assertSame(200, $cancelled->status);
        $this->assertStringNotContainsString('btn-whatsapp', $cancelled->body);
        $day = $this->get('/agenda', ['prof' => (string) $this->doc['id'], 'data' => $this->mon]);
        $this->assertStringNotContainsString('wa.me', $day->body, 'riscada nao tem botao');
    }

    public function test_nutritionist_unreleased_row_has_no_button(): void
    {
        Container::accessGrants()->grant((int) $this->adult['id'], (int) $this->nutri['id'], (int) $this->doc['id']);
        $this->book($this->nutri, $this->adult, '08:00');
        $this->loginAs($this->nutri);
        $this->assertStringContainsString('btn-whatsapp', $this->get('/agenda', ['data' => $this->mon])->body);
        $this->db->run('UPDATE professional_access SET revoked_at = NOW() WHERE professional_id = ?', [(int) $this->nutri['id']]);
        $hidden = $this->get('/agenda', ['data' => $this->mon]);
        $this->assertStringContainsString('Paciente sem liberação', $hidden->body);
        $this->assertStringNotContainsString('wa.me', $hidden->body);
    }

    public function test_admin_texts_page(): void
    {
        $this->loginAs($this->sec);
        $this->assertSame(403, $this->get('/admin/agenda')->status);
        $this->assertSame(403, $this->post('/admin/agenda', ['agenda_text_adult' => 'x', 'agenda_text_child' => ''])->status);
        $this->loginAs($this->doc);
        $this->assertSame(403, $this->get('/admin/agenda')->status);

        $this->loginAs($this->admin);
        $form = $this->get('/admin/agenda');
        $this->assertSame(200, $form->status);
        $this->assertStringContainsString(e(Container::reminderText()->templates()['adult']), $form->body);
        $this->assertStringContainsString('{endereço}', $form->body);
        $this->assertStringContainsString('Restaurar texto padrão', $form->body);
        $this->assertStringContainsString('Agenda (avisos)', $form->body);

        $res = $this->post('/admin/agenda', ['agenda_text_adult' => '  Oi {nome}, até {dia}!  ', 'agenda_text_child' => '']);
        $this->assertSame(302, $res->status);
        $this->assertSame('/admin/agenda', $res->headers['Location']);
        $row = $this->db->one("SELECT value FROM settings WHERE `key` = 'agenda_text_adult'");
        $this->assertSame('Oi {nome}, até {dia}!', $row['value']);
        $this->assertSame('', (string) $this->db->one("SELECT value FROM settings WHERE `key` = 'agenda_text_child'")['value']);
        $audit = $this->db->one("SELECT * FROM audit_log WHERE action = 'update' AND entity = 'settings' ORDER BY id DESC LIMIT 1");
        $this->assertNotNull($audit);
        $this->assertStringContainsString('Oi {nome}', (string) $audit['after_json']);

        $tooLong = $this->post('/admin/agenda', ['agenda_text_adult' => str_repeat('a', 1001), 'agenda_text_child' => '']);
        $this->assertSame(422, $tooLong->status);
        $this->assertSame('Oi {nome}, até {dia}!', $this->db->one("SELECT value FROM settings WHERE `key` = 'agenda_text_adult'")['value']);

        $restore = $this->post('/admin/agenda', ['agenda_text_adult' => 'Oi {nome}, até {dia}!', 'agenda_text_child' => 'Filho {nome}', 'restaurar' => 'adult']);
        $this->assertSame(302, $restore->status);
        $this->assertSame('', $this->db->one("SELECT value FROM settings WHERE `key` = 'agenda_text_adult'")['value']);
        $this->assertSame('Filho {nome}', $this->db->one("SELECT value FROM settings WHERE `key` = 'agenda_text_child'")['value']);
    }

    public function test_guardian_phone_saved_and_audited(): void
    {
        $this->loginAs($this->sec);
        $pid = (int) $this->child['id'];
        $page = $this->get('/pacientes/' . $pid . '/responsaveis');
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('name="celular"', $page->body);
        $this->assertStringContainsString('11 3456-7890', $page->body);

        $add = $this->post('/pacientes/' . $pid . '/responsaveis', ['nome' => 'Pai Teste', 'email' => 'pai.teste@example.test', 'parentesco' => 'pai', 'celular' => '(21) 99999-0000']);
        $this->assertSame(302, $add->status);
        $dad = $this->db->one("SELECT * FROM users WHERE email = 'pai.teste@example.test'");
        $this->assertSame('(21) 99999-0000', $dad['phone']);

        $long = $this->post('/pacientes/' . $pid . '/responsaveis', ['nome' => 'Tio', 'email' => 'tio@example.test', 'parentesco' => 'outro', 'celular' => str_repeat('1', 31)]);
        $this->assertStringContainsString('Celular: no máximo 30 caracteres.', $long->body);
        $this->assertNull($this->db->one("SELECT id FROM users WHERE email = 'tio@example.test'"));

        $link = $this->db->one('SELECT id FROM guardian_links WHERE patient_id = ? AND user_id = ?', [$pid, (int) $this->mom['id']]);
        $res = $this->post('/responsaveis/' . (int) $link['id'] . '/celular', ['celular' => ' 11 91234-5678 ']);
        $this->assertSame(302, $res->status);
        $this->assertSame('11 91234-5678', $this->db->one('SELECT phone FROM users WHERE id = ?', [(int) $this->mom['id']])['phone']);
        $audit = $this->db->one("SELECT * FROM audit_log WHERE action = 'update' AND entity = 'users' AND entity_id = ? ORDER BY id DESC LIMIT 1", [(int) $this->mom['id']]);
        $this->assertNotNull($audit);
        $this->assertSame(['phone' => '11 3456-7890'], json_decode((string) $audit['before_json'], true));
        $this->assertSame(['phone' => '11 91234-5678'], json_decode((string) $audit['after_json'], true));

        $this->post('/responsaveis/' . (int) $link['id'] . '/celular', ['celular' => '']);
        $this->assertNull($this->db->one('SELECT phone FROM users WHERE id = ?', [(int) $this->mom['id']])['phone']);

        $this->loginAs($this->nutri);
        $this->assertSame(403, $this->post('/responsaveis/' . (int) $link['id'] . '/celular', ['celular' => '1'])->status);
    }
}
