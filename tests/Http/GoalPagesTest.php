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

final class GoalPagesTest extends DbTestCase
{
    private function get(string $path, array $query = []): Response
    {
        return App::create()->handle(new Request('GET', $path, $query));
    }

    private function post(string $path, array $data = []): Response
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

    private function req(): Request
    {
        return new Request('POST', '/');
    }

    private function template(string $text, string $category, int $active = 1): int
    {
        $med = $this->makeUser('medico');
        return Container::goals()->saveTemplate(null, [
            'family_text' => $text, 'child_text' => '', 'emoji' => '💧', 'category' => $category,
            'times_per_week' => 7, 'active' => $active, 'sort_order' => 1,
        ], (int) $med['id'], $this->req());
    }

    private function goal(int $pid, string $text, string $category, array $over = []): int
    {
        $med = $this->makeUser('medico');
        return Container::goals()->create($pid, $over + [
            'family_text' => $text, 'child_text' => '', 'emoji' => '⭐', 'category' => $category,
            'times_per_week' => 3, 'start_date' => date('Y-m-d', strtotime('-20 days')), 'end_date' => null, 'secret' => 0,
        ], (int) $med['id'], $this->req());
    }

    /** @return array{0:array,1:array} medico e paciente */
    private function fixture(): array
    {
        $med = $this->makeUser('medico');
        $p = $this->makePatient(['created_by' => $med['id'], 'responsible_professional_id' => $med['id']]);
        return [$med, $p];
    }

    private function form(array $over = []): array
    {
        return $over + [
            'family_text' => 'Beber água nas refeições', 'child_text' => '', 'emoji' => '💧', 'category' => 'hidratacao',
            'times_per_week' => '5', 'start_date' => date('Y-m-d'), 'end_date' => '',
        ];
    }

    public function test_template_library_edit_and_deactivate(): void
    {
        [$med, $p] = $this->fixture();
        $this->loginAs($med);
        $res = $this->post('/metas-modelo/novo', [
            'family_text' => 'Brincar ao ar livre', 'child_text' => 'Brinquei lá fora', 'emoji' => '🌳',
            'category' => 'atividade', 'times_per_week' => '3', 'active' => '1', 'sort_order' => '5',
        ]);
        $this->assertSame(302, $res->status);
        $t = $this->db->one("SELECT * FROM goal_templates WHERE family_text = 'Brincar ao ar livre'");
        $this->assertNotNull($t);
        $this->assertStringContainsString('Brincar ao ar livre', $this->get('/metas-modelo')->body);
        $this->assertStringContainsString('Brincar ao ar livre', $this->get('/pacientes/' . $p['id'] . '/metas/nova')->body);

        $res = $this->post('/metas-modelo/' . $t['id'] . '/editar', [
            'family_text' => 'Brincar ao ar livre', 'child_text' => '', 'emoji' => '🌳',
            'category' => 'atividade', 'times_per_week' => '3', 'sort_order' => '5', // sem 'active': desativa
        ]);
        $this->assertSame(302, $res->status);
        $this->assertSame(0, (int) $this->db->one('SELECT active FROM goal_templates WHERE id = ?', [$t['id']])['active']);
        $this->assertStringNotContainsString('Brincar ao ar livre', $this->get('/pacientes/' . $p['id'] . '/metas/nova')->body);
        $this->assertStringContainsString('Brincar ao ar livre', $this->get('/metas-modelo')->body);

        $bad = $this->post('/metas-modelo/novo', ['family_text' => 'x', 'emoji' => '', 'category' => 'xx', 'times_per_week' => '9']);
        $this->assertSame(200, $bad->status);
        $this->assertStringContainsString('aviso-erro', $bad->body);
        $this->assertNotNull($this->db->one("SELECT id FROM audit_log WHERE action = 'create' AND entity = 'goal_templates'"));
    }

    public function test_doctor_creates_goal_from_template_and_sees_grid(): void
    {
        [$med, $p] = $this->fixture();
        $pid = (int) $p['id'];
        $tid = $this->template('Beber água em todas as refeições', 'hidratacao');
        $this->loginAs($med);

        $page = $this->get('/pacientes/' . $pid . '/metas/nova', ['modelo' => (string) $tid]);
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('value="Beber água em todas as refeições"', $page->body);

        $res = $this->post('/pacientes/' . $pid . '/metas/nova', $this->form(['template_id' => (string) $tid, 'family_text' => 'Beber água em todas as refeições']));
        $this->assertSame(302, $res->status);
        $goal = $this->db->one('SELECT * FROM patient_goals WHERE patient_id = ?', [$pid]);
        $this->assertSame($tid, (int) $goal['template_id']);
        $this->assertSame(5, (int) $goal['times_per_week']);

        // Familia marcou 3 dias da semana passada: aparece "3/5" na grade.
        $resp = $this->makeUser('responsavel');
        $this->db->run('UPDATE patient_goals SET start_date = ? WHERE id = ?', [date('Y-m-d', strtotime('-30 days')), $goal['id']]);
        $lastMon = \App\Goals\GoalCalc::weekStart(date('Y-m-d', strtotime('-7 days')));
        foreach ([0, 1, 2] as $i) {
            $this->db->insert('goal_checks', [
                'goal_id' => $goal['id'], 'day' => date('Y-m-d', strtotime($lastMon . " +$i days")),
                'checked_by' => $resp['id'], 'checked_by_role' => 'responsavel',
            ]);
        }
        $grid = $this->get('/pacientes/' . $pid . '/metas');
        $this->assertSame(200, $grid->status);
        $this->assertStringContainsString('Beber água em todas as refeições', $grid->body);
        $this->assertStringContainsString('3/5', $grid->body);
        $this->assertNotNull($this->db->one(
            "SELECT id FROM audit_log WHERE action = 'view' AND entity = 'patient_goals' AND patient_id = ?", [$pid]
        ));

        // Edicao pela medica.
        $res = $this->post('/metas/' . $goal['id'] . '/editar', $this->form([
            'family_text' => 'Beber mais água', 'category' => 'hidratacao', 'times_per_week' => '7', 'start_date' => $this->db->one('SELECT start_date FROM patient_goals WHERE id = ?', [$goal['id']])['start_date'],
        ]));
        $this->assertSame(302, $res->status);
        $this->assertSame('Beber mais água', $this->db->one('SELECT family_text FROM patient_goals WHERE id = ?', [$goal['id']])['family_text']);
    }

    public function test_nutritionist_only_nutrition_goals(): void
    {
        [$med, $p] = $this->fixture();
        $pid = (int) $p['id'];
        $nutri = $this->makeUser('nutricionista');
        Container::accessGrants()->grant($pid, (int) $nutri['id'], (int) $med['id']);
        $water = $this->goal($pid, 'Beber agua sempre', 'hidratacao');
        $sleep = $this->goal($pid, 'Dormir cedo sempre', 'sono');
        $this->loginAs($nutri);
        $start = date('Y-m-d', strtotime('-20 days'));

        $body = $this->get('/pacientes/' . $pid . '/metas')->body;
        $this->assertStringContainsString('Beber agua sempre', $body);
        $this->assertStringNotContainsString('Dormir cedo sempre', $body);
        $ficha = $this->get('/pacientes/' . $pid)->body;
        $this->assertStringContainsString('Beber agua sempre', $ficha);
        $this->assertStringNotContainsString('Dormir cedo sempre', $ficha);

        // cria hidratacao; sono e recusado
        $this->assertSame(302, $this->post('/pacientes/' . $pid . '/metas/nova', $this->form())->status);
        $this->assertSame(403, $this->post('/pacientes/' . $pid . '/metas/nova', $this->form(['category' => 'sono']))->status);

        // sono: 403 ao abrir/editar/encerrar/excluir
        $this->assertSame(403, $this->get('/metas/' . $sleep . '/editar')->status);
        $this->assertSame(403, $this->post('/metas/' . $sleep . '/editar', $this->form(['start_date' => $start]))->status);
        $this->assertSame(403, $this->post('/metas/' . $sleep . '/encerrar')->status);
        $this->assertSame(403, $this->post('/metas/' . $sleep . '/excluir')->status);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) n FROM patient_goals WHERE id = ? AND deleted_at IS NOT NULL', [$sleep])['n']);

        // categoria nao muda
        $res = $this->post('/metas/' . $water . '/editar', $this->form(['category' => 'sono', 'family_text' => 'Beber agua muito', 'start_date' => $start]));
        $this->assertSame(302, $res->status);
        $row = $this->db->one('SELECT * FROM patient_goals WHERE id = ?', [$water]);
        $this->assertSame('hidratacao', $row['category']);
        $this->assertSame('Beber agua muito', $row['family_text']);

        // paciente nao liberado: 403
        $other = $this->makePatient(['created_by' => $med['id'], 'responsible_professional_id' => $med['id']]);
        $this->assertSame(403, $this->get('/pacientes/' . $other['id'] . '/metas')->status);
        $this->assertSame(403, $this->post('/pacientes/' . $other['id'] . '/metas/nova', $this->form())->status);

        // biblioteca: so categorias nutricionais
        $tSono = $this->template('Modelo de sono', 'sono');
        $tAli = $this->template('Modelo de fruta', 'alimentacao');
        $lib = $this->get('/metas-modelo')->body;
        $this->assertStringContainsString('Modelo de fruta', $lib);
        $this->assertStringNotContainsString('Modelo de sono', $lib);
        $this->assertSame(403, $this->get('/metas-modelo/' . $tSono . '/editar')->status);
        $this->assertSame(200, $this->get('/metas-modelo/' . $tAli . '/editar')->status);
        $this->assertSame(403, $this->post('/metas-modelo/novo', ['family_text' => 'Novo sono', 'emoji' => '🌙', 'category' => 'sono', 'times_per_week' => '7', 'active' => '1'])->status);
        $this->post('/metas-modelo/' . $tAli . '/editar', ['family_text' => 'Modelo de fruta', 'emoji' => '🍎', 'category' => 'sono', 'times_per_week' => '7', 'active' => '1']);
        $this->assertSame('alimentacao', $this->db->one('SELECT category FROM goal_templates WHERE id = ?', [$tAli])['category']);
    }

    public function test_secret_option_requires_child_account(): void
    {
        [$med, $p] = $this->fixture();
        $pid = (int) $p['id'];
        $this->loginAs($med);
        $this->assertStringNotContainsString('name="secret"', $this->get('/pacientes/' . $pid . '/metas/nova')->body);
        $res = $this->post('/pacientes/' . $pid . '/metas/nova', $this->form(['secret' => '1']));
        $this->assertSame(200, $res->status);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) n FROM patient_goals WHERE patient_id = ?', [$pid])['n']);

        $kid = $this->makeUser('crianca', ['email' => null, 'username' => 'kid.' . bin2hex(random_bytes(3))]);
        $this->db->run('UPDATE patients SET child_user_id = ? WHERE id = ?', [$kid['id'], $pid]);
        $this->assertStringContainsString('name="secret"', $this->get('/pacientes/' . $pid . '/metas/nova')->body);
        $this->assertSame(302, $this->post('/pacientes/' . $pid . '/metas/nova', $this->form(['secret' => '1']))->status);
        $g = $this->db->one('SELECT id, secret FROM patient_goals WHERE patient_id = ?', [$pid]);
        $this->assertSame(1, (int) $g['secret']);
        $this->assertStringContainsString('sigilosa', $this->get('/pacientes/' . $pid . '/metas')->body);
    }

    public function test_end_and_delete_goal_audited(): void
    {
        [$med, $p] = $this->fixture();
        $pid = (int) $p['id'];
        $g1 = $this->goal($pid, 'Meta para encerrar', 'rotina');
        $g2 = $this->goal($pid, 'Meta para excluir', 'rotina');
        $this->loginAs($med);

        $this->assertSame(302, $this->post('/metas/' . $g1 . '/encerrar')->status);
        $this->assertSame(date('Y-m-d'), $this->db->one('SELECT end_date FROM patient_goals WHERE id = ?', [$g1])['end_date']);
        $this->assertNotNull($this->db->one("SELECT id FROM audit_log WHERE action = 'end' AND entity_id = ?", [$g1]));
        $this->assertStringContainsString('Encerradas', $this->get('/pacientes/' . $pid . '/metas')->body);

        $this->assertSame(302, $this->post('/metas/' . $g2 . '/excluir')->status);
        $this->assertNotNull($this->db->one('SELECT id FROM patient_goals WHERE id = ? AND deleted_at IS NOT NULL', [$g2]));
        $this->assertNotNull($this->db->one("SELECT id FROM audit_log WHERE action = 'delete' AND entity_id = ?", [$g2]));
        $this->assertStringNotContainsString('Meta para excluir', $this->get('/pacientes/' . $pid . '/metas')->body);
        $this->assertSame(404, $this->get('/metas/' . $g2 . '/editar')->status);
        $this->assertSame(404, $this->get('/metas/999999/editar')->status);
    }

    public function test_family_and_secretary_get_403(): void
    {
        [$med, $p] = $this->fixture();
        $pid = (int) $p['id'];
        $g = $this->goal($pid, 'Meta visivel', 'rotina');
        $resp = $this->makeUser('responsavel');
        $this->db->insert('guardian_links', ['patient_id' => $pid, 'user_id' => $resp['id'], 'relationship' => 'mãe', 'created_by' => $med['id']]);
        foreach ([$this->makeUser('secretaria'), $resp] as $u) {
            $this->loginAs($u);
            foreach (['/pacientes/' . $pid . '/metas', '/pacientes/' . $pid . '/metas/nova', '/metas/' . $g . '/editar', '/metas-modelo', '/metas-modelo/novo'] as $path) {
                $this->assertSame(403, $this->get($path)->status, $u['role'] . ' GET ' . $path);
            }
            foreach (['/pacientes/' . $pid . '/metas/nova', '/metas/' . $g . '/editar', '/metas/' . $g . '/encerrar', '/metas/' . $g . '/excluir', '/metas-modelo/novo'] as $path) {
                $this->assertSame(403, $this->post($path, $this->form())->status, $u['role'] . ' POST ' . $path);
            }
        }
        $this->assertNull($this->db->one('SELECT id FROM patient_goals WHERE id = ? AND (deleted_at IS NOT NULL OR end_date IS NOT NULL)', [$g]));
    }

    public function test_patient_card_shows_goals(): void
    {
        [$med, $p] = $this->fixture();
        $pid = (int) $p['id'];
        $g = $this->goal($pid, 'Comer fruta no lanche', 'alimentacao', ['times_per_week' => 2]);
        $resp = $this->makeUser('responsavel');
        $lastMon = \App\Goals\GoalCalc::weekStart(date('Y-m-d', strtotime('-7 days')));
        foreach ([0, 1] as $i) {
            $this->db->insert('goal_checks', [
                'goal_id' => $g, 'day' => date('Y-m-d', strtotime($lastMon . " +$i days")),
                'checked_by' => $resp['id'], 'checked_by_role' => 'responsavel',
            ]);
        }
        $this->loginAs($med);
        $body = $this->get('/pacientes/' . $pid)->body;
        $this->assertStringContainsString('id="cartao-metas"', $body);
        $this->assertStringContainsString('Comer fruta no lanche', $body);
        $this->assertStringContainsString('2/2', $body);
        $this->assertStringContainsString('Estrelas', $body);
        $this->assertStringContainsString('/pacientes/' . $pid . '/metas', $body);
    }
}
