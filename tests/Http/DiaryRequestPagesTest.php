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

final class DiaryRequestPagesTest extends DbTestCase
{
    private function get(string $path): Response
    {
        return App::create()->handle(new Request('GET', $path, []));
    }

    private function post(string $path, array $data): Response
    {
        $data['_csrf'] = Csrf::token();
        return App::create()->handle(new Request('POST', $path, [], $data, [], ['REMOTE_ADDR' => '127.0.0.1']));
    }

    private function loginAs(array $u): void
    {
        if (in_array($u['role'], ['medico', 'nutricionista', 'secretaria'], true)) {
            $this->db->run('UPDATE users SET totp_enabled = 1 WHERE id = ?', [$u['id']]);
        }
        Session::reset();
        Container::auth()->completeLogin((int) $u['id'], new Request('POST', '/login'));
    }

    /** @return array{0:array,1:array} medico logado e paciente menor de idade */
    private function setupDoctor(string $birth = '2015-05-10'): array
    {
        $med = $this->makeUser('medico');
        $p = $this->makePatient(['birth_date' => $birth, 'responsible_professional_id' => $med['id'], 'created_by' => $med['id']]);
        $this->loginAs($med);
        return [$med, $p];
    }

    private function form(array $over = []): array
    {
        return $over + ['type' => 'agua', 'weekdays' => ['1', '2', '3', '4', '5', '6', '7'], 'start_date' => date('Y-m-d'), 'end_date' => '', 'guidance' => 'Beber ao longo do dia'];
    }

    private function n(string $sql, array $params = []): int
    {
        return (int) $this->db->one($sql, $params)['n'];
    }

    private function make(int $pid, array $med, array $over = []): int
    {
        return Container::diaryRequests()->create($pid, $over + [
            'type' => 'agua', 'weekdays' => [1, 2, 3, 4, 5, 6, 7], 'start_date' => date('Y-m-d', strtotime('-3 days')),
        ], $med, date('Y-m-d'), new Request('POST', '/'));
    }

    public function test_doctor_creates_edits_ends_request(): void
    {
        [, $p] = $this->setupDoctor();
        $pid = (int) $p['id'];
        $this->assertSame(200, $this->get('/pacientes/' . $pid . '/diario')->status);
        $this->assertSame(200, $this->get('/pacientes/' . $pid . '/diario/pedidos/novo')->status);

        $res = $this->post('/pacientes/' . $pid . '/diario/pedidos/novo', $this->form());
        $this->assertSame(302, $res->status);
        $this->assertSame('/pacientes/' . $pid . '/diario', $res->headers['Location']);
        $id = (int) $this->db->one('SELECT id FROM diary_requests WHERE patient_id = ?', [$pid])['id'];
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM audit_log WHERE action = 'create' AND entity = 'diary_requests'"));
        $this->assertStringContainsString('Pedido criado.', $this->get('/pacientes/' . $pid . '/diario')->body);

        $this->assertSame(200, $this->get('/diario/pedidos/' . $id . '/editar')->status);
        $res = $this->post('/diario/pedidos/' . $id . '/editar', $this->form(['guidance' => 'Nova orientacao']));
        $this->assertSame(302, $res->status);
        $this->assertSame('Nova orientacao', $this->db->one('SELECT guidance FROM diary_requests WHERE id = ?', [$id])['guidance']);
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM audit_log WHERE action = 'update' AND entity = 'diary_requests'"));

        $res = $this->post('/diario/pedidos/' . $id . '/encerrar', []);
        $this->assertSame(302, $res->status);
        $this->assertSame('/pacientes/' . $pid . '/diario', $res->headers['Location']);
        $this->assertSame(date('Y-m-d'), $this->db->one('SELECT end_date FROM diary_requests WHERE id = ?', [$id])['end_date']);
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM audit_log WHERE action = 'end' AND entity = 'diary_requests'"));

        $b = $this->post('/pacientes/' . $pid . '/diario/pedidos/novo', $this->form(['type' => 'tela']));
        $this->assertSame(302, $b->status);
        $id2 = (int) $this->db->one("SELECT id FROM diary_requests WHERE type = 'tela'")['id'];
        $res = $this->post('/diario/pedidos/' . $id2 . '/excluir', []);
        $this->assertSame(302, $res->status);
        $this->assertNotNull($this->db->one('SELECT deleted_at FROM diary_requests WHERE id = ?', [$id2])['deleted_at']);
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM audit_log WHERE action = 'delete' AND entity = 'diary_requests'"));
    }

    public function test_nutritionist_sees_only_nutritional_types_in_form_and_list(): void
    {
        [$med, $p] = $this->setupDoctor();
        $pid = (int) $p['id'];
        $agua = $this->make($pid, $med);
        $sono = $this->make($pid, $med, ['type' => 'sono']);
        $nutri = $this->makeUser('nutricionista');
        Container::accessGrants()->grant($pid, (int) $nutri['id'], (int) $med['id']);
        $this->loginAs($nutri);

        $form = $this->get('/pacientes/' . $pid . '/diario/pedidos/novo');
        $this->assertSame(200, $form->status);
        $this->assertStringContainsString('value="alimentacao"', $form->body);
        $this->assertStringContainsString('value="agua"', $form->body);
        $this->assertStringNotContainsString('value="sono"', $form->body);
        $this->assertStringNotContainsString('value="tela"', $form->body);

        $list = $this->get('/pacientes/' . $pid . '/diario');
        $this->assertSame(200, $list->status);
        $this->assertStringContainsString('Água', $list->body);
        $this->assertStringNotContainsString('Sono', $list->body);

        $this->assertSame(200, $this->get('/diario/pedidos/' . $agua . '/editar')->status);
        $this->assertSame(404, $this->get('/diario/pedidos/' . $sono . '/editar')->status);
        $this->assertSame(404, $this->post('/diario/pedidos/' . $sono . '/encerrar', [])->status);
        $this->assertSame(404, $this->post('/diario/pedidos/' . $sono . '/excluir', [])->status);
        $this->assertNull($this->db->one('SELECT deleted_at FROM diary_requests WHERE id = ?', [$sono])['deleted_at']);
    }

    public function test_family_and_secretary_get_403_before_gate(): void
    {
        [$med, $p] = $this->setupDoctor();
        $pid = (int) $p['id'];
        $id = $this->make($pid, $med);
        $sec = $this->makeUser('secretaria');
        $resp = $this->makeUser('responsavel');
        $this->db->insert('guardian_links', ['patient_id' => $pid, 'user_id' => $resp['id'], 'relationship' => 'mãe', 'created_by' => $med['id']]);
        foreach ([$sec, $resp] as $u) {
            $this->loginAs($u);
            $this->assertSame(403, $this->get('/pacientes/' . $pid . '/diario')->status, $u['role']);
            $this->assertSame(403, $this->get('/pacientes/' . $pid . '/diario/pedidos/novo')->status, $u['role']);
            $this->assertSame(403, $this->post('/pacientes/' . $pid . '/diario/pedidos/novo', $this->form(['type' => 'tela']))->status, $u['role']);
            $this->assertSame(403, $this->get('/diario/pedidos/' . $id . '/editar')->status, $u['role']);
            $this->assertSame(403, $this->post('/diario/pedidos/' . $id . '/excluir', [])->status, $u['role']);
        }
        $this->assertNull($this->db->one('SELECT deleted_at FROM diary_requests WHERE id = ?', [$id])['deleted_at']);
        $this->assertGreaterThanOrEqual(2, $this->n("SELECT COUNT(*) n FROM audit_log WHERE action = 'denied' AND JSON_VALUE(after_json, '$.section') = 'diario'"));
    }

    public function test_validation_errors_return_422(): void
    {
        [, $p] = $this->setupDoctor();
        $pid = (int) $p['id'];
        $res = $this->post('/pacientes/' . $pid . '/diario/pedidos/novo', $this->form(['weekdays' => []]));
        $this->assertSame(422, $res->status);
        $this->assertStringContainsString('Escolha pelo menos um dia da semana.', $res->body);
        $this->assertSame(0, $this->n('SELECT COUNT(*) n FROM diary_requests'));

        $this->assertSame(302, $this->post('/pacientes/' . $pid . '/diario/pedidos/novo', $this->form())->status);
        $dup = $this->post('/pacientes/' . $pid . '/diario/pedidos/novo', $this->form());
        $this->assertSame(422, $dup->status);
        $this->assertStringContainsString('Já existe um pedido de Água', $dup->body);
        $this->assertSame(1, $this->n('SELECT COUNT(*) n FROM diary_requests'));
    }

    public function test_secret_checkbox_only_for_doctor_and_minor(): void
    {
        [$med, $p] = $this->setupDoctor();
        $pid = (int) $p['id'];
        $form = $this->get('/pacientes/' . $pid . '/diario/pedidos/novo');
        $this->assertStringContainsString('name="secret"', $form->body);
        $this->assertStringContainsString('name="secret_shown"', $form->body);

        $adult = $this->makePatient(['birth_date' => '1985-01-01', 'responsible_professional_id' => $med['id'], 'created_by' => $med['id']]);
        $this->assertStringNotContainsString('name="secret"', $this->get('/pacientes/' . $adult['id'] . '/diario/pedidos/novo')->body);

        $this->assertSame(302, $this->post('/pacientes/' . $pid . '/diario/pedidos/novo', $this->form(['secret' => '1', 'secret_shown' => '1']))->status);
        $id = (int) $this->db->one('SELECT id FROM diary_requests')['id'];
        $this->assertSame(1, (int) $this->db->one('SELECT secret FROM diary_requests WHERE id = ?', [$id])['secret']);
        $this->assertMatchesRegularExpression('/name="secret"[^>]*checked/', $this->get('/diario/pedidos/' . $id . '/editar')->body);

        $nutri = $this->makeUser('nutricionista');
        Container::accessGrants()->grant($pid, (int) $nutri['id'], (int) $med['id']);
        $this->loginAs($nutri);
        $this->assertStringNotContainsString('name="secret"', $this->get('/pacientes/' . $pid . '/diario/pedidos/novo')->body);
        $this->assertSame(404, $this->get('/diario/pedidos/' . $id . '/editar')->status);
    }

    public function test_type_locked_when_entries_exist(): void
    {
        [$med, $p] = $this->setupDoctor();
        $pid = (int) $p['id'];
        $id = $this->make($pid, $med);
        $this->db->insert('diary_entries', [
            'request_id' => $id, 'day' => date('Y-m-d'), 'glasses' => 3, 'recorded_by' => $med['id'], 'recorded_by_role' => 'medico',
        ]);
        $this->assertMatchesRegularExpression('/<select[^>]*name="type"[^>]*disabled/', $this->get('/diario/pedidos/' . $id . '/editar')->body);
        $res = $this->post('/diario/pedidos/' . $id . '/editar', $this->form(['type' => 'tela']));
        $this->assertSame(422, $res->status);
        $this->assertSame('agua', $this->db->one('SELECT type FROM diary_requests WHERE id = ?', [$id])['type']);
    }

    public function test_patient_card_lists_requests(): void
    {
        [$med, $p] = $this->setupDoctor();
        $pid = (int) $p['id'];
        $ficha = $this->get('/pacientes/' . $pid)->body;
        $this->assertStringContainsString('Nenhum pedido de registro.', $ficha);
        $this->assertStringContainsString('Pedir registros', $ficha);
        $this->make($pid, $med);
        $this->make($pid, $med, ['type' => 'sono']);
        $ficha = $this->get('/pacientes/' . $pid)->body;
        $this->assertStringContainsString('Abrir diário', $ficha);
        $this->assertStringContainsString('Água', $ficha);
        $this->assertStringContainsString('Sono', $ficha);

        $nutri = $this->makeUser('nutricionista');
        Container::accessGrants()->grant($pid, (int) $nutri['id'], (int) $med['id']);
        $this->loginAs($nutri);
        $ficha = $this->get('/pacientes/' . $pid)->body;
        $this->assertStringContainsString('cartao-diario', $ficha);
        $this->assertStringNotContainsString('Sono', $ficha);
    }
}
