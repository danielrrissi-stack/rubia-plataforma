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

final class ChildAccountTest extends DbTestCase
{
    private function get(string $path): Response
    {
        return App::create()->handle(new Request('GET', $path));
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

    private function tempPassword(Response $res): string
    {
        $this->assertSame(1, preg_match('#<code class="senha-provisoria">([^<]+)</code>#', $res->body, $m), 'senha provisoria na tela');
        return html_entity_decode($m[1]);
    }

    private function childAccount(array $patient): array
    {
        $u = $this->makeUser('crianca', ['email' => null, 'username' => 'kid.' . bin2hex(random_bytes(3))]);
        $this->db->run('UPDATE patients SET child_user_id = ? WHERE id = ?', [$u['id'], $patient['id']]);
        return $u;
    }

    public function test_doctor_creates_child_account_once(): void
    {
        $med = $this->makeUser('medico');
        $p = $this->makePatient(['full_name' => 'Ana Souza Lima', 'created_by' => $med['id'], 'responsible_professional_id' => $med['id']]);
        $pid = (int) $p['id'];
        $this->loginAs($med);

        $page = $this->get('/pacientes/' . $pid . '/acesso-crianca');
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('name="usuario"', $page->body);
        $this->assertStringContainsString('/pacientes/' . $pid . '/acesso-crianca', $this->get('/pacientes/' . $pid)->body);

        // Usuario invalido: recusado.
        $bad = $this->post('/pacientes/' . $pid . '/acesso-crianca', ['acao' => 'criar', 'usuario' => 'ana@souza']);
        $this->assertSame(302, $bad->status);
        $this->assertNull($this->db->one('SELECT child_user_id c FROM patients WHERE id = ?', [$pid])['c']);

        $res = $this->post('/pacientes/' . $pid . '/acesso-crianca', ['acao' => 'criar', 'usuario' => ' Ana.Souza ']);
        $this->assertSame(200, $res->status);
        $this->assertSame('no-store', $res->headers['Cache-Control'] ?? null);
        $this->assertStringContainsString('ana.souza', $res->body);
        $temp = $this->tempPassword($res);
        $this->assertGreaterThanOrEqual(10, strlen($temp));
        $this->assertMatchesRegularExpression('/^[a-hj-km-np-z2-9]+$/', $temp);

        $uid = (int) $this->db->one('SELECT child_user_id c FROM patients WHERE id = ?', [$pid])['c'];
        $user = $this->db->one('SELECT * FROM users WHERE id = ?', [$uid]);
        $this->assertSame('crianca', $user['role']);
        $this->assertSame('ana.souza', $user['username']);
        $this->assertNull($user['email']);
        $this->assertSame(1, (int) $user['must_change_password']);
        $this->assertSame(1, (int) $user['active']);
        $this->assertTrue(password_verify($temp, (string) $user['password_hash']));

        $audit = $this->db->one("SELECT * FROM audit_log WHERE action = 'create' AND entity = 'users' AND entity_id = ?", [$uid]);
        $this->assertNotNull($audit);
        $this->assertSame($pid, (int) $audit['patient_id']);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM audit_log WHERE CONCAT_WS(" ", before_json, after_json) LIKE ?', ['%' . $temp . '%'])['c']);

        // Senha mostrada uma vez: a tela nao a repete.
        $again = $this->get('/pacientes/' . $pid . '/acesso-crianca');
        $this->assertStringNotContainsString($temp, $again->body);
        $this->assertStringContainsString('ana.souza', $again->body);

        // Segunda tentativa: recusada.
        $second = $this->post('/pacientes/' . $pid . '/acesso-crianca', ['acao' => 'criar', 'usuario' => 'ana.outra']);
        $this->assertSame(302, $second->status);
        $this->assertNull(Container::users()->findByUsername('ana.outra'));
        $this->assertSame($uid, (int) $this->db->one('SELECT child_user_id c FROM patients WHERE id = ?', [$pid])['c']);

        // Usuario ja usado por outro paciente: recusado.
        $q = $this->makePatient(['created_by' => $med['id'], 'responsible_professional_id' => $med['id']]);
        $dup = $this->post('/pacientes/' . $q['id'] . '/acesso-crianca', ['acao' => 'criar', 'usuario' => 'ANA.SOUZA']);
        $this->assertSame(302, $dup->status);
        $this->assertNull($this->db->one('SELECT child_user_id c FROM patients WHERE id = ?', [$q['id']])['c']);

        // Paciente com conta 'paciente': recusado.
        $pac = $this->makeUser('paciente');
        $adult = $this->makePatient(['user_id' => $pac['id'], 'created_by' => $med['id'], 'responsible_professional_id' => $med['id']]);
        $this->post('/pacientes/' . $adult['id'] . '/acesso-crianca', ['acao' => 'criar', 'usuario' => 'adulto.x']);
        $this->assertNull(Container::users()->findByUsername('adulto.x'));
        $this->assertNull($this->db->one('SELECT child_user_id c FROM patients WHERE id = ?', [$adult['id']])['c']);

        // A crianca entra com a senha provisoria e e obrigada a troca-la.
        Session::reset();
        $login = $this->post('/login', ['email' => 'ana.souza', 'senha' => $temp]);
        $this->assertSame('/', $login->headers['Location']);
        $this->assertSame('/senha/trocar', $this->get('/')->headers['Location']);
    }

    public function test_only_doctor_manages_child_account(): void
    {
        $med = $this->makeUser('medico');
        $p = $this->makePatient(['created_by' => $med['id'], 'responsible_professional_id' => $med['id']]);
        $nutri = $this->makeUser('nutricionista');
        Container::accessGrants()->grant((int) $p['id'], (int) $nutri['id'], (int) $med['id']);
        $resp = $this->makeUser('responsavel');
        $this->db->insert('guardian_links', ['patient_id' => $p['id'], 'user_id' => $resp['id'], 'relationship' => 'mãe', 'created_by' => $med['id']]);
        $this->db->run('UPDATE consent_terms SET active = 0'); // responsavel sem termo pendente
        foreach ([$nutri, $this->makeUser('secretaria'), $resp] as $u) {
            $this->loginAs($u);
            $this->assertSame(403, $this->get('/pacientes/' . $p['id'] . '/acesso-crianca')->status, $u['role']);
            $this->assertSame(403, $this->post('/pacientes/' . $p['id'] . '/acesso-crianca', ['acao' => 'criar', 'usuario' => 'nao.pode'])->status, $u['role']);
        }
        $this->assertNull(Container::users()->findByUsername('nao.pode'));
    }

    public function test_doctor_resets_deactivates_and_sets_secrecy(): void
    {
        $med = $this->makeUser('medico');
        $p = $this->makePatient(['created_by' => $med['id'], 'responsible_professional_id' => $med['id']]);
        $pid = (int) $p['id'];
        $kid = $this->childAccount($p);
        $this->db->run('UPDATE users SET failed_logins = 3 WHERE id = ?', [$kid['id']]);
        $this->loginAs($med);
        $path = '/pacientes/' . $pid . '/acesso-crianca';
        $this->assertStringContainsString('Responsáveis veem as marcações feitas pelo próprio paciente', $this->get($path)->body);

        $res = $this->post($path, ['acao' => 'redefinir']);
        $this->assertSame(200, $res->status);
        $temp = $this->tempPassword($res);
        $row = $this->db->one('SELECT * FROM users WHERE id = ?', [$kid['id']]);
        $this->assertTrue(password_verify($temp, (string) $row['password_hash']));
        $this->assertFalse(password_verify('senha-teste-123', (string) $row['password_hash']));
        $this->assertSame(1, (int) $row['must_change_password']);
        $this->assertSame(0, (int) $row['failed_logins']);
        $this->assertNotNull($this->db->one("SELECT 1 FROM audit_log WHERE action = 'reset_password' AND entity = 'users' AND entity_id = ?", [$kid['id']]));
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM audit_log WHERE CONCAT_WS(" ", before_json, after_json) LIKE ?', ['%' . $temp . '%'])['c']);

        $this->post($path, ['acao' => 'desativar']);
        $this->assertSame(0, (int) $this->db->one('SELECT active FROM users WHERE id = ?', [$kid['id']])['active']);
        $this->assertNotNull($this->db->one("SELECT 1 FROM audit_log WHERE action = 'deactivate' AND entity = 'users' AND entity_id = ?", [$kid['id']]));
        $this->post($path, ['acao' => 'reativar']);
        $this->assertSame(1, (int) $this->db->one('SELECT active FROM users WHERE id = ?', [$kid['id']])['active']);
        $this->assertNotNull($this->db->one("SELECT 1 FROM audit_log WHERE action = 'reactivate' AND entity = 'users' AND entity_id = ?", [$kid['id']]));

        $this->post($path, ['acao' => 'sigilo']); // caixa desmarcada
        $this->assertSame(0, (int) $this->db->one('SELECT guardians_see_child_checks g FROM patients WHERE id = ?', [$pid])['g']);
        $a = $this->db->one("SELECT * FROM audit_log WHERE action = 'update' AND entity = 'patients' AND entity_id = ? ORDER BY id DESC LIMIT 1", [$pid]);
        $this->assertNotNull($a);
        $this->assertStringContainsString('guardians_see_child_checks', (string) $a['after_json']);
        $this->post($path, ['acao' => 'sigilo', 'ver_marcacoes' => '1']);
        $this->assertSame(1, (int) $this->db->one('SELECT guardians_see_child_checks g FROM patients WHERE id = ?', [$pid])['g']);

        // Sem conta de crianca, as acoes sobre a conta sao recusadas.
        $q = $this->makePatient(['created_by' => $med['id'], 'responsible_professional_id' => $med['id']]);
        $this->assertSame(302, $this->post('/pacientes/' . $q['id'] . '/acesso-crianca', ['acao' => 'redefinir'])->status);
        $this->assertStringNotContainsString('Responsáveis veem as marcações', $this->get('/pacientes/' . $q['id'] . '/acesso-crianca')->body);
    }

    public function test_child_is_confined_to_own_goals_area(): void
    {
        $p = $this->makePatient(['full_name' => 'Ana Souza']);
        $other = $this->makePatient();
        $pid = (int) $p['id'];
        $kid = $this->childAccount($p);
        $this->loginAs($kid);
        $home = '/portal/p/' . $pid . '/metas';

        $gets = [
            '/', '/pacientes', '/pacientes/' . $pid, '/pacientes/' . $pid . '/crescimento', '/pacientes/' . $pid . '/acesso-crianca',
            '/portal', '/portal/p/' . $pid, '/portal/p/' . $pid . '/crescimento', '/portal/p/' . $pid . '/alimentacao',
            '/portal/p/' . $pid . '/exames', '/portal/p/' . $pid . '/medidas/nova', '/catalogo-exames', '/equipe', '/maioridade',
            '/consentimento', '/2fa/configurar', '/admin/backups', '/conta-inexistente-nao-roteada-nao-conta',
            '/portal/p/' . $other['id'] . '/crescimento', '/portal/p/' . $other['id'],
        ];
        foreach ($gets as $path) {
            $res = $this->get($path);
            if ($path === '/conta-inexistente-nao-roteada-nao-conta') {
                $this->assertSame(404, $res->status);
                continue;
            }
            $this->assertSame(302, $res->status, $path);
            $this->assertSame($home, $res->headers['Location'], $path);
        }

        // Prefixos da area de metas de outro paciente nunca passam (rotas das Tasks 5/6 ainda nao existem).
        $this->assertFalse(\App\Auth\ChildArea::allows('GET', '/portal/p/' . $other['id'] . '/metas', $pid));
        $this->assertFalse(\App\Auth\ChildArea::allows('POST', '/portal/p/' . $other['id'] . '/metas/1/marcar', $pid));
        $this->assertFalse(\App\Auth\ChildArea::allows('GET', '/portal/p/' . $pid . '/metasx', $pid));
        $this->assertFalse(\App\Auth\ChildArea::allows('GET', '/portal/p/' . $pid . '0/metas', $pid));
        $this->assertTrue(\App\Auth\ChildArea::allows('GET', $home, $pid));
        $this->assertTrue(\App\Auth\ChildArea::allows('POST', $home . '/5/marcar', $pid));
        $this->assertTrue(\App\Auth\ChildArea::allows('GET', '/portal/p/' . $pid . '/cartoes/3', $pid));
        $this->assertFalse(\App\Auth\ChildArea::allows('GET', '/portal/p/' . $pid . '/cartoes', $pid));
        $this->assertFalse(\App\Auth\ChildArea::allows('GET', $home, null));

        // Conta propria: liberada, sem oferta de 2FA.
        $acc = $this->get('/portal/conta');
        $this->assertSame(200, $acc->status);
        $this->assertStringNotContainsString('/2fa/configurar', $acc->body);
        $this->assertStringNotContainsString('/portal/p/' . $pid . '/crescimento', $acc->body);

        // POST fora da lista: 403, nada gravado.
        $posts = [
            '/pacientes/' . $pid . '/editar' => ['nome' => 'X'],
            '/portal/p/' . $pid . '/medidas/nova' => ['data_medida' => date('d/m/Y'), 'peso' => '30'],
            '/consentimento' => ['aceito' => '1'],
            '/2fa/configurar' => ['codigo' => '000000'],
            '/pacientes/' . $pid . '/acesso-crianca' => ['acao' => 'desativar'],
            '/pacientes/novo' => ['nome' => 'X'],
        ];
        foreach ($posts as $path => $data) {
            $this->assertSame(403, $this->post($path, $data)->status, 'POST ' . $path);
        }
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM measurements WHERE patient_id = ?', [$pid])['c']);
        $this->assertSame(1, (int) $this->db->one('SELECT active FROM users WHERE id = ?', [$kid['id']])['active']);

        // Trocar a propria senha e sair: liberados.
        $res = $this->post('/portal/conta/senha', ['atual' => 'senha-teste-123', 'nova' => 'nova-senha-kid-1', 'confirmacao' => 'nova-senha-kid-1']);
        $this->assertSame('/portal/conta', $res->headers['Location']);
        $this->assertSame('/login', $this->post('/logout')->headers['Location']);
    }

    public function test_child_without_linked_patient_sees_unavailable_page(): void
    {
        $kid = $this->makeUser('crianca', ['email' => null, 'username' => 'sem.vinculo']);
        $this->loginAs($kid);
        $res = $this->get('/portal');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('Acesso ainda não disponível', $res->body);
        $this->assertSame('/portal', $this->get('/pacientes')->headers['Location']);
        $this->assertSame('/portal', $this->get('/')->headers['Location']);
    }

    public function test_majority_transfer_converts_child_account(): void
    {
        $med = $this->makeUser('medico');
        $year = (int) date('Y');
        $p = $this->makePatient(['birth_date' => ($year - 19) . '-01-01', 'created_by' => $med['id'], 'responsible_professional_id' => $med['id']]);
        $pid = (int) $p['id'];
        $kid = $this->childAccount($p);
        $mom = $this->makeUser('responsavel');
        $this->db->insert('guardian_links', ['patient_id' => $pid, 'user_id' => $mom['id'], 'relationship' => 'mãe', 'created_by' => $med['id']]);
        $goalId = $this->db->insert('patient_goals', [
            'patient_id' => $pid, 'family_text' => 'Beber água', 'emoji' => '💧', 'category' => 'hidratacao',
            'times_per_week' => 7, 'start_date' => date('Y-m-d', strtotime('-3 days')), 'created_by' => $med['id'],
        ]);
        $this->db->insert('goal_checks', ['goal_id' => $goalId, 'day' => date('Y-m-d'), 'checked_by' => $kid['id'], 'checked_by_role' => 'crianca']);

        // O alerta lista o paciente e o formulario pede o e-mail.
        $this->assertContains($pid, array_map(static fn(array $r): int => (int) $r['id'], Container::majority()->pending(date('Y-m-d'))));
        $this->loginAs($med);
        $page = $this->get('/maioridade');
        $this->assertStringContainsString('id="email' . $pid . '"', $page->body);
        $this->assertStringContainsString($kid['username'], $page->body);

        try {
            Container::majority()->decide($pid, 'transferir', null, (int) $med['id'], '');
            $this->fail('sem e-mail deveria recusar');
        } catch (\InvalidArgumentException) {
        }

        Container::majority()->decide($pid, 'transferir', null, (int) $med['id'], 'Ana.Adulta@Example.test');
        $user = $this->db->one('SELECT * FROM users WHERE id = ?', [$kid['id']]);
        $this->assertSame('paciente', $user['role']);
        $this->assertSame('ana.adulta@example.test', $user['email']);
        $this->assertSame($kid['username'], $user['username']);
        $patient = $this->db->one('SELECT * FROM patients WHERE id = ?', [$pid]);
        $this->assertSame((int) $kid['id'], (int) $patient['user_id']);
        $this->assertNull($patient['child_user_id']);
        $this->assertSame(1, (int) $this->db->one('SELECT COUNT(*) c FROM goal_checks WHERE goal_id = ? AND checked_by = ?', [$goalId, $kid['id']])['c']);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM guardian_links WHERE patient_id = ? AND active = 1', [$pid])['c']);
        $this->assertSame(1, (int) $this->db->one('SELECT COUNT(*) c FROM users WHERE id = ?', [$kid['id']])['c']);
        $this->assertNotNull($this->db->one("SELECT 1 FROM audit_log WHERE action = 'update' AND entity = 'users' AND entity_id = ?", [$kid['id']]));
        $this->assertTrue(Container::patientScope()->canAccess($user, $pid));
    }
}
