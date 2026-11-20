<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Core\App;
use App\Core\Container;
use App\Core\Csrf;
use App\Core\Session;
use App\Core\Request;
use App\Core\Response;
use Tests\DbTestCase;

final class LoginFlowTest extends DbTestCase
{
    private function get(string $path): Response
    {
        return App::create()->handle(new Request('GET', $path));
    }

    private function post(string $path, array $data): Response
    {
        $data['_csrf'] = Csrf::token();
        return App::create()->handle(new Request('POST', $path, [], $data, [], ['REMOTE_ADDR' => '127.0.0.1']));
    }

    public function testGuestRedirectedToLogin(): void
    {
        $res = $this->get('/');
        $this->assertSame(302, $res->status);
        $this->assertSame('/login', $res->headers['Location']);
    }

    public function testLoginPageRenders(): void
    {
        $res = $this->get('/login');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('name="senha"', $res->body);
    }

    public function testValidLoginRedirectsHomeAndShowsWelcome(): void
    {
        $u = $this->makeUser('medico', ['name' => 'Rubia Teste']);
        $res = $this->post('/login', ['email' => $u['email'], 'senha' => 'senha-teste-123']);
        $this->assertSame(302, $res->status);
        $this->assertSame('/', $res->headers['Location']);
        // Equipe precisa ter 2FA ativo para ver qualquer pagina (Task 5).
        $this->db->run('UPDATE users SET totp_enabled = 1 WHERE id = ?', [$u['id']]);
        $home = $this->get('/');
        $this->assertSame(200, $home->status);
        $this->assertStringContainsString('Bem-vindo, Rubia Teste', $home->body);
        $this->assertStringContainsString('action="/logout"', $home->body);
    }

    public function testInvalidLoginShowsGenericError(): void
    {
        $u = $this->makeUser('medico');
        $res = $this->post('/login', ['email' => $u['email'], 'senha' => 'errada']);
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('E-mail ou senha inválidos.', $res->body);
    }

    public function testLogoutEndsSession(): void
    {
        $u = $this->makeUser('medico');
        $this->post('/login', ['email' => $u['email'], 'senha' => 'senha-teste-123']);
        $res = $this->post('/logout', []);
        $this->assertSame('/login', $res->headers['Location']);
        $this->assertSame('/login', $this->get('/')->headers['Location']);
    }

    public function testForgotPasswordSameMessageAndOnlyExistingQueuesMail(): void
    {
        $u = $this->makeUser('medico');
        $a = $this->post('/senha/esqueci', ['email' => 'nao-existe@example.test']);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM mail_queue')['c']);
        $b = $this->post('/senha/esqueci', ['email' => $u['email']]);
        $this->assertSame(1, (int) $this->db->one('SELECT COUNT(*) c FROM mail_queue')['c']);
        $msg = 'Se o e-mail estiver cadastrado, enviaremos um link para redefinir a senha.';
        $this->assertStringContainsString($msg, $a->body);
        $this->assertStringContainsString($msg, $b->body);
        $mail = $this->db->one('SELECT * FROM mail_queue');
        $this->assertSame('Redefinição de senha — Dra. Rubia', $mail['subject']);
        $this->assertMatchesRegularExpression('#http://localhost:8001/senha/definir/[0-9a-f]{64}#', $mail['body_html']);
    }

    public function testResetFlowSetsPassword(): void
    {
        $u = $this->makeUser('medico');
        $this->post('/senha/esqueci', ['email' => $u['email']]);
        $mail = $this->db->one('SELECT * FROM mail_queue');
        preg_match('#/senha/definir/([0-9a-f]{64})#', $mail['body_html'], $m);
        $path = '/senha/definir/' . $m[1];
        $this->assertSame(200, $this->get($path)->status);

        $short = $this->post($path, ['senha' => 'curta', 'confirmacao' => 'curta']);
        $this->assertStringContainsString('pelo menos 10 caracteres', $short->body);
        $mismatch = $this->post($path, ['senha' => 'uma-senha-longa-1', 'confirmacao' => 'outra-senha-longa']);
        $this->assertStringContainsString('não conferem', $mismatch->body);

        $ok = $this->post($path, ['senha' => 'uma-senha-longa-1', 'confirmacao' => 'uma-senha-longa-1']);
        $this->assertSame('/login', $ok->headers['Location']);
        $this->assertSame(404, $this->get($path)->status);
        $login = $this->post('/login', ['email' => $u['email'], 'senha' => 'uma-senha-longa-1']);
        $this->assertSame('/', $login->headers['Location']);
    }

    public function testInvalidTokenIs404(): void
    {
        $this->assertSame(404, $this->get('/senha/definir/' . str_repeat('a', 64))->status);
    }

    public function test_login_with_username_and_email(): void
    {
        $child = $this->makeUser('crianca', ['email' => null, 'username' => 'ana.souza']);
        $this->assertStringContainsString('E-mail ou usuário', $this->get('/login')->body);

        // Usuario com maiusculas e espacos e normalizado.
        $res = $this->post('/login', ['email' => '  Ana.Souza ', 'senha' => 'senha-teste-123']);
        $this->assertSame(302, $res->status);
        $this->assertSame('/', $res->headers['Location']);
        $this->assertSame((int) $child['id'], Session::get('uid'));
        $this->post('/logout', []);

        // E-mail continua funcionando (inclusive com maiusculas).
        $mom = $this->makeUser('responsavel', ['email' => 'bia.mae@example.test']);
        $res = $this->post('/login', ['email' => 'Bia.Mae@Example.test', 'senha' => 'senha-teste-123']);
        $this->assertSame('/', $res->headers['Location']);
        $this->assertSame((int) $mom['id'], Session::get('uid'));
        $this->post('/logout', []);

        // Usuario igual a parte de um e-mail nao entra na conta do e-mail.
        $res = $this->post('/login', ['email' => 'bia.mae', 'senha' => 'senha-teste-123']);
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('E-mail ou senha inválidos.', $res->body);
        $this->assertNull(Session::get('uid'));

        // Senha errada: mesma mensagem generica.
        $res = $this->post('/login', ['email' => 'ana.souza', 'senha' => 'errada-errada']);
        $this->assertStringContainsString('E-mail ou senha inválidos.', $res->body);
        $this->assertNull(Session::get('uid'));

        // Usuario inexistente ou fora do formato: mesma mensagem.
        foreach (['nao.existe', 'a', 'ana souza@'] as $login) {
            $res = $this->post('/login', ['email' => $login, 'senha' => 'senha-teste-123']);
            $this->assertStringContainsString('E-mail ou senha inválidos.', $res->body, $login);
            $this->assertNull(Session::get('uid'));
        }

        // Conta de crianca desativada: recusada com a mesma mensagem.
        $this->db->run('UPDATE users SET active = 0 WHERE id = ?', [$child['id']]);
        $res = $this->post('/login', ['email' => 'ana.souza', 'senha' => 'senha-teste-123']);
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('E-mail ou senha inválidos.', $res->body);
        $this->assertNull(Session::get('uid'));
    }

    public function test_username_login_uses_same_lockout(): void
    {
        $child = $this->makeUser('crianca', ['email' => null, 'username' => 'leo.k']);
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'LEO.K', 'senha' => 'errada-' . $i]);
        }
        $res = $this->post('/login', ['email' => 'leo.k', 'senha' => 'senha-teste-123']);
        $this->assertStringContainsString('Conta bloqueada temporariamente', $res->body);
        $this->assertNull(Session::get('uid'));
        $this->assertTrue(Container::auth()->isLocked((int) $child['id']));
    }

    public function test_temporary_password_forces_change(): void
    {
        $child = $this->makeUser('crianca', ['email' => null, 'username' => 'davi.t', 'must_change_password' => 1]);
        $p = $this->makePatient(['child_user_id' => $child['id']]);
        $pid = (int) $p['id'];
        $this->assertSame('/', $this->post('/login', ['email' => 'davi.t', 'senha' => 'senha-teste-123'])->headers['Location']);

        // Todo GET vai para a troca; POST fora da lista e negado.
        foreach (['/', '/portal', '/portal/conta', '/pacientes', '/pacientes/' . $pid] as $path) {
            $res = $this->get($path);
            $this->assertSame(302, $res->status, $path);
            $this->assertSame('/senha/trocar', $res->headers['Location'], $path);
        }
        $res = $this->post('/portal/conta/senha', ['atual' => 'senha-teste-123', 'nova' => 'nova-senha-123', 'confirmacao' => 'nova-senha-123']);
        $this->assertSame(403, $res->status);

        $form = $this->get('/senha/trocar');
        $this->assertSame(200, $form->status);
        $this->assertStringContainsString('name="confirmacao"', $form->body);

        $short = $this->post('/senha/trocar', ['nova' => 'curta', 'confirmacao' => 'curta']);
        $this->assertSame(200, $short->status);
        $this->assertStringContainsString('pelo menos 10 caracteres', $short->body);
        $mismatch = $this->post('/senha/trocar', ['nova' => 'nova-senha-123', 'confirmacao' => 'outra-senha-123']);
        $this->assertStringContainsString('não é igual', $mismatch->body);
        $same = $this->post('/senha/trocar', ['nova' => 'senha-teste-123', 'confirmacao' => 'senha-teste-123']);
        $this->assertStringContainsString('diferente da provisória', $same->body);
        $this->assertSame(1, (int) $this->db->one('SELECT must_change_password m FROM users WHERE id = ?', [$child['id']])['m']);

        $ok = $this->post('/senha/trocar', ['nova' => 'nova-senha-123', 'confirmacao' => 'nova-senha-123']);
        $this->assertSame(302, $ok->status);
        $row = $this->db->one('SELECT * FROM users WHERE id = ?', [$child['id']]);
        $this->assertSame(0, (int) $row['must_change_password']);
        $this->assertTrue(password_verify('nova-senha-123', (string) $row['password_hash']));
        $audit = $this->db->one(
            "SELECT * FROM audit_log WHERE action = 'update' AND entity = 'users' AND entity_id = ? ORDER BY id DESC LIMIT 1",
            [$child['id']]
        );
        $this->assertNotNull($audit);
        $this->assertStringNotContainsString('nova-senha-123', (string) json_encode($audit));

        // Depois da troca, a crianca volta para a area dela; /senha/trocar nao e mais exigida.
        $this->assertSame('/portal/p/' . $pid . '/metas', $this->get('/')->headers['Location']);
        $this->assertSame(302, $this->get('/senha/trocar')->status);
    }

    public function test_temporary_password_applies_to_any_role_and_logout_is_free(): void
    {
        $m = $this->makeUser('medico', ['totp_enabled' => 1, 'must_change_password' => 1]);
        Container::auth()->completeLogin((int) $m['id'], new Request('POST', '/login'));
        $this->assertSame('/senha/trocar', $this->get('/')->headers['Location']);
        $this->assertSame('/senha/trocar', $this->get('/equipe')->headers['Location']);
        $this->assertSame(403, $this->post('/pacientes/novo', ['nome' => 'X'])->status);
        $this->assertSame('/login', $this->post('/logout', [])->headers['Location']);

        // Equipe sem 2FA e com senha provisoria: sem laco entre /2fa/configurar e /senha/trocar.
        $n = $this->makeUser('nutricionista', ['must_change_password' => 1]);
        Container::auth()->completeLogin((int) $n['id'], new Request('POST', '/login'));
        $this->assertSame('/2fa/configurar', $this->get('/')->headers['Location']);
        $this->assertSame('/senha/trocar', $this->get('/2fa/configurar')->headers['Location']);
        $this->assertSame(200, $this->get('/senha/trocar')->status);
        $this->post('/senha/trocar', ['nova' => 'nova-senha-123', 'confirmacao' => 'nova-senha-123']);
        $this->assertSame(200, $this->get('/2fa/configurar')->status);
    }
}
