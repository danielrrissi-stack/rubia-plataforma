<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Core\App;
use App\Core\Csrf;
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
}
