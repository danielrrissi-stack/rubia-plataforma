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

final class PortalGoalsTest extends DbTestCase
{
    private function get(string $path, array $query = []): Response
    {
        return App::create()->handle(new Request('GET', $path, $query));
    }

    private function post(string $path, array $data = [], array $server = []): Response
    {
        $data['_csrf'] = Csrf::token();
        return App::create()->handle(new Request('POST', $path, [], $data, [], ['REMOTE_ADDR' => '127.0.0.1'] + $server));
    }

    private function loginAs(array $u): void
    {
        Session::reset();
        Container::auth()->completeLogin((int) $u['id'], new Request('POST', '/login'));
    }

    private function day(int $offset): string
    {
        return date('Y-m-d', strtotime(($offset >= 0 ? '+' : '') . $offset . ' days'));
    }

    private function stars(Response $res): int
    {
        $this->assertSame(1, preg_match('/data-stars>(\d+)</', $res->body, $m), 'total de estrelas na tela');
        return (int) $m[1];
    }

    private function goal(int $pid, array $over = []): int
    {
        $med = $this->makeUser('medico');
        return Container::goals()->create($pid, $over + [
            'family_text' => 'Beber água nas refeições', 'child_text' => '', 'emoji' => '💧', 'category' => 'hidratacao',
            'times_per_week' => 7, 'start_date' => $this->day(-30), 'end_date' => null, 'secret' => 0,
        ], (int) $med['id'], new Request('POST', '/'));
    }

    /** @return array{0:array,1:array,2:array} responsavel, paciente (8 anos), medico */
    private function family(array $patientOver = []): array
    {
        $med = $this->makeUser('medico');
        $g = $this->makeUser('responsavel');
        $p = $this->makePatient($patientOver + [
            'full_name' => 'Ana Souza', 'birth_date' => (date('Y') - 8) . '-03-10', 'sex' => 'F',
            'responsible_professional_id' => $med['id'], 'created_by' => $med['id'],
        ]);
        $this->db->insert('guardian_links', ['patient_id' => $p['id'], 'user_id' => $g['id'], 'relationship' => 'mãe', 'created_by' => $med['id']]);
        return [$g, $p, $med];
    }

    private function childAccount(array $patient): array
    {
        $u = $this->makeUser('crianca', ['email' => null, 'username' => 'kid.' . bin2hex(random_bytes(3))]);
        $this->db->run('UPDATE patients SET child_user_id = ? WHERE id = ?', [$u['id'], $patient['id']]);
        return $u;
    }

    private function checkCount(int $goalId): int
    {
        return (int) $this->db->one('SELECT COUNT(*) c FROM goal_checks WHERE goal_id = ?', [$goalId])['c'];
    }

    public function test_guardian_marks_and_unmarks_within_window(): void
    {
        [$g, $p] = $this->family();
        $pid = (int) $p['id'];
        $goal = $this->goal($pid);
        $this->loginAs($g);
        $base = '/portal/p/' . $pid . '/metas/' . $goal;

        $res = $this->post($base . '/marcar', ['dia' => $this->day(-6)]);
        $this->assertSame(302, $res->status);
        $this->assertSame('/portal/p/' . $pid . '/metas', $res->headers['Location']);
        $this->assertSame(1, $this->checkCount($goal));

        $res = $this->post($base . '/marcar', ['dia' => $this->day(-7), 'semana' => '2026-11-09']);
        $this->assertSame('/portal/p/' . $pid . '/metas?semana=2026-11-09', $res->headers['Location']);
        $this->assertSame(1, $this->checkCount($goal));
        $this->assertStringContainsString('últimos 7 dias', $this->get('/portal/p/' . $pid . '/metas')->body);

        $this->post($base . '/marcar', ['dia' => $this->day(1)]);
        $this->assertSame(1, $this->checkCount($goal));

        $json = $this->post($base . '/marcar', ['dia' => $this->day(-7)], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertSame(422, $json->status);
        $this->assertFalse(json_decode($json->body, true)['ok']);

        $this->post($base . '/desmarcar', ['dia' => $this->day(-6)]);
        $this->assertSame(0, $this->checkCount($goal));

        $json = $this->post($base . '/marcar', ['dia' => $this->day(0)], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertSame(['ok' => true, 'checked' => true, 'stars' => 1], json_decode($json->body, true));
    }

    public function test_guardian_counts_exclude_secret_goal(): void
    {
        [$g, $p] = $this->family();
        $pid = (int) $p['id'];
        $kid = $this->childAccount($p);
        $open = $this->goal($pid, ['family_text' => 'Meta aberta']);
        $secret = $this->goal($pid, ['family_text' => 'Meta secreta do adolescente', 'secret' => 1]);
        for ($i = 0; $i < 12; $i++) {
            $this->db->insert('goal_checks', ['goal_id' => $secret, 'day' => $this->day(-$i), 'checked_by' => $kid['id'], 'checked_by_role' => 'crianca']);
        }
        $this->db->insert('goal_checks', ['goal_id' => $open, 'day' => $this->day(0), 'checked_by' => $g['id'], 'checked_by_role' => 'responsavel']);

        $this->loginAs($g);
        $res = $this->get('/portal/p/' . $pid . '/metas');
        $this->assertSame(1, $this->stars($res));
        $this->assertStringContainsString('Meta aberta', $res->body);
        $this->assertStringNotContainsString('secreta', $res->body);
        $this->assertStringNotContainsString('10 estrelas', $res->body);
        $this->assertStringNotContainsString('Semana completa', $res->body);

        $this->loginAs($kid);
        $res = $this->get('/portal/p/' . $pid . '/metas');
        $this->assertSame(13, $this->stars($res));
        $this->assertStringContainsString('Meta secreta do adolescente', $res->body);
        $this->assertStringContainsString('10 estrelas', $res->body);
    }

    public function test_guardians_dont_see_child_checks_when_disabled(): void
    {
        [$g, $p] = $this->family();
        $pid = (int) $p['id'];
        $kid = $this->childAccount($p);
        $goal = $this->goal($pid);
        $this->db->insert('goal_checks', ['goal_id' => $goal, 'day' => $this->day(0), 'checked_by' => $kid['id'], 'checked_by_role' => 'crianca']);
        $this->db->insert('goal_checks', ['goal_id' => $goal, 'day' => $this->day(-1), 'checked_by' => $g['id'], 'checked_by_role' => 'responsavel']);

        $this->loginAs($g);
        $this->assertSame(2, $this->stars($this->get('/portal/p/' . $pid . '/metas')));
        $this->db->run('UPDATE patients SET guardians_see_child_checks = 0 WHERE id = ?', [$pid]);
        $this->assertSame(1, $this->stars($this->get('/portal/p/' . $pid . '/metas')));
        $this->loginAs($kid);
        $this->assertSame(2, $this->stars($this->get('/portal/p/' . $pid . '/metas')));
    }

    public function test_child_sees_only_own_area_texts(): void
    {
        [, $p] = $this->family();
        $pid = (int) $p['id'];
        $kid = $this->childAccount($p);
        $this->goal($pid, ['family_text' => 'Texto da família', 'child_text' => 'Bebi água nas refeições']);
        $this->loginAs($kid);
        $res = $this->get('/portal/p/' . $pid . '/metas');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('Oi, Ana!', $res->body);
        $this->assertStringContainsString('Bebi água nas refeições', $res->body);
        $this->assertStringNotContainsString('Texto da família', $res->body);
        foreach (['/crescimento', '/alimentacao', '/exames', '/medidas', 'Início', 'Trocar de pessoa'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $res->body);
        }
        $this->assertStringContainsString('aria-label="Marcar Bebi água nas refeições em ', $res->body);
        $this->assertStringContainsString('aria-pressed="false"', $res->body);
        $this->assertStringContainsString('/portal/conta', $res->body);
        $this->assertStringNotContainsString('Para você ler', $res->body);
    }

    public function test_adult_patient_sees_family_texts(): void
    {
        $med = $this->makeUser('medico');
        $u = $this->makeUser('paciente');
        $p = $this->makePatient(['full_name' => 'Carlos Lima', 'birth_date' => (date('Y') - 40) . '-06-15', 'sex' => 'M', 'user_id' => $u['id'],
            'responsible_professional_id' => $med['id'], 'created_by' => $med['id']]);
        $pid = (int) $p['id'];
        $this->goal($pid, ['family_text' => 'Texto da família', 'child_text' => 'Texto lúdico']);
        $this->loginAs($u);
        $res = $this->get('/portal/p/' . $pid . '/metas');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('Suas metas', $res->body);
        $this->assertStringContainsString('Texto da família', $res->body);
        $this->assertStringNotContainsString('Texto lúdico', $res->body);
        $this->assertStringNotContainsString('Oi, ', $res->body);
    }

    public function test_other_patient_403(): void
    {
        [$g, $p] = $this->family();
        $kid = $this->childAccount($p);
        $other = $this->makePatient(['full_name' => 'Outro Paciente', 'created_by' => $g['id']]);
        $oid = (int) $other['id'];
        $otherGoal = $this->goal($oid);
        $ownGoal = $this->goal((int) $p['id']);
        $home = '/portal/p/' . (int) $p['id'] . '/metas';

        // Crianca: GET de outro paciente volta para a area dela; POST em meta alheia -> 403.
        $this->loginAs($kid);
        $res = $this->get('/portal/p/' . $oid . '/metas');
        $this->assertSame(302, $res->status);
        $this->assertSame($home, $res->headers['Location']);
        $this->assertSame(403, $this->post('/portal/p/' . $oid . '/metas/' . $otherGoal . '/marcar', ['dia' => $this->day(0)])->status);
        $this->assertSame(0, $this->checkCount($otherGoal));
        foreach (['/pacientes', '/portal/p/' . $p['id'] . '/crescimento', '/portal/p/' . $p['id'] . '/exames', '/catalogo-exames', '/portal'] as $path) {
            $res = $this->get($path);
            $this->assertSame(302, $res->status, $path);
            $this->assertSame($home, $res->headers['Location'], $path);
        }

        // Responsavel: outro paciente 403; meta de outro paciente pela URL do proprio -> 404 sem marcar.
        $this->loginAs($g);
        $this->assertSame(403, $this->get('/portal/p/' . $oid . '/metas')->status);
        $this->assertSame(403, $this->post('/portal/p/' . $oid . '/metas/' . $otherGoal . '/marcar', ['dia' => $this->day(0)])->status);
        $this->assertSame(404, $this->post('/portal/p/' . $p['id'] . '/metas/' . $otherGoal . '/marcar', ['dia' => $this->day(0)])->status);
        $this->assertSame(0, $this->checkCount($otherGoal));
        $this->assertSame(0, $this->checkCount($ownGoal));
    }

    public function test_double_submit_single_check(): void
    {
        [$g, $p] = $this->family();
        $pid = (int) $p['id'];
        $goal = $this->goal($pid);
        $this->loginAs($g);
        $url = '/portal/p/' . $pid . '/metas/' . $goal . '/marcar';
        $this->post($url, ['dia' => $this->day(0)]);
        $res = $this->post($url, ['dia' => $this->day(0)]);
        $this->assertSame(302, $res->status);
        $this->assertSame(1, $this->checkCount($goal));
        $this->assertSame(1, $this->stars($this->get('/portal/p/' . $pid . '/metas')));
    }
}
