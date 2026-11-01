<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Core\App;
use App\Core\Csrf;
use App\Core\Mailer;
use App\Core\Request;
use App\Core\Response;
use Tests\DbTestCase;

final class UserAdminTest extends DbTestCase
{
    private array $admin;

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
        $this->db->run('UPDATE users SET totp_enabled = 1 WHERE id = ?', [$u['id']]);
        \App\Core\Container::auth()->completeLogin((int) $u['id'], new Request('POST', '/login'));
    }

    private function asAdmin(): void
    {
        $this->admin = $this->makeUser('medico', ['is_admin' => 1]);
        $this->loginAs($this->admin);
    }

    private function row(int $id): array
    {
        return $this->db->one('SELECT * FROM users WHERE id = ?', [$id]);
    }

    public function testAdminCreatesNutritionistAndInviteIsQueued(): void
    {
        $this->asAdmin();
        $res = $this->post('/equipe/novo', [
            'nome' => 'Nina Nutri', 'email' => 'Nina@Example.test', 'papel' => 'nutricionista',
            'registro' => 'CRN-3 12345', 'administrador' => '1',
        ]);
        $this->assertSame(302, $res->status);
        $u = $this->db->one("SELECT * FROM users WHERE email = 'nina@example.test'");
        $this->assertNotNull($u);
        $this->assertSame('nutricionista', $u['role']);
        $this->assertSame(0, (int) $u['is_admin']);
        $this->assertNull($u['password_hash']);
        $this->assertCount(1, $this->db->all('SELECT * FROM mail_queue'));
        $mail = $this->db->one('SELECT * FROM mail_queue ORDER BY id DESC LIMIT 1');
        $this->assertSame('Convite — Plataforma Dra. Rubia', $mail['subject']);
        $this->assertStringContainsString('/senha/definir/', $mail['body_html']);
        $this->assertStringContainsString('72 horas', $mail['body_html']);
        $this->assertStringContainsString('nutricionista', mb_strtolower($mail['body_html']));
        $audit = $this->db->one("SELECT * FROM audit_log WHERE action='create' AND entity='users'");
        $this->assertNotNull($audit);
        $this->assertStringNotContainsString('password_hash', (string) $audit['after_json']);
        $this->assertStringNotContainsString('totp_secret', (string) $audit['after_json']);
    }

    public function testSecretaryGets403(): void
    {
        $this->loginAs($this->makeUser('secretaria'));
        $this->assertSame(403, $this->get('/equipe')->status);
        $this->assertSame(403, $this->post('/equipe/novo', ['nome' => 'x'])->status);
    }

    public function testNonAdminDoctorGets403(): void
    {
        $this->loginAs($this->makeUser('medico'));
        $this->assertSame(403, $this->get('/equipe')->status);
    }

    public function testListShowsOnlyStaffAndNavLink(): void
    {
        $this->asAdmin();
        $fam = $this->makeUser('responsavel', ['name' => 'Familia Fulana']);
        $body = $this->get('/equipe')->body;
        $this->assertStringContainsString($this->admin['name'], $body);
        $this->assertStringNotContainsString('Familia Fulana', $body);
        $this->assertStringContainsString('href="/equipe"', $body);
    }

    public function testDuplicateEmailAndInvalidRoleRejected(): void
    {
        $this->asAdmin();
        $other = $this->makeUser('secretaria', ['email' => 'dup@example.test']);
        $res = $this->post('/equipe/novo', ['nome' => 'X', 'email' => 'DUP@example.test', 'papel' => 'secretaria']);
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('Já existe um usuário com este e-mail.', $res->body);
        $res = $this->post('/equipe/novo', ['nome' => 'X', 'email' => 'x@example.test', 'papel' => 'paciente']);
        $this->assertSame(200, $res->status);
        $this->assertNull($this->db->one("SELECT id FROM users WHERE email = 'x@example.test'"));
        $res = $this->post('/equipe/novo', ['nome' => 'X', 'email' => 'x@example.test', 'papel' => 'medico']);
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('registro profissional', mb_strtolower($res->body));
    }

    public function testAdminCannotDeactivateSelfButCanOthers(): void
    {
        $this->asAdmin();
        $res = $this->post('/equipe/' . $this->admin['id'] . '/desativar');
        $this->assertSame(302, $res->status);
        $this->assertSame(1, (int) $this->row((int) $this->admin['id'])['active']);
        $this->assertNotNull(\App\Core\Session::flash('erro'));

        $o = $this->makeUser('secretaria');
        $this->post('/equipe/' . $o['id'] . '/desativar');
        $this->assertSame(0, (int) $this->row((int) $o['id'])['active']);
        $this->post('/equipe/' . $o['id'] . '/reativar');
        $this->assertSame(1, (int) $this->row((int) $o['id'])['active']);
        $this->assertSame(2, (int) $this->db->one("SELECT COUNT(*) c FROM audit_log WHERE action='update' AND entity='users'")['c']);
    }

    public function testEditRulesAndSelfAdminProtection(): void
    {
        $this->asAdmin();
        $id = (int) $this->admin['id'];
        $res = $this->post("/equipe/$id/editar", [
            'nome' => 'Rubia', 'email' => $this->admin['email'], 'papel' => 'medico', 'registro' => 'CRM 1',
        ]);
        $this->assertSame(200, $res->status);
        $this->assertSame(1, (int) $this->row($id)['is_admin']);

        $o = $this->makeUser('secretaria');
        $res = $this->post('/equipe/' . $o['id'] . '/editar', [
            'nome' => 'Novo Nome', 'email' => $o['email'], 'papel' => 'medico', 'registro' => 'CRM 2', 'administrador' => '1',
        ]);
        $this->assertSame(302, $res->status);
        $r = $this->row((int) $o['id']);
        $this->assertSame('medico', $r['role']);
        $this->assertSame(1, (int) $r['is_admin']);
        $this->assertSame('Novo Nome', $r['name']);
        $audit = $this->db->one("SELECT * FROM audit_log WHERE action='update' AND entity='users'");
        $this->assertStringNotContainsString('password_hash', (string) $audit['before_json'] . $audit['after_json']);
    }

    public function testEditingFamilyAccountIs404(): void
    {
        $this->asAdmin();
        $f = $this->makeUser('responsavel');
        $this->assertSame(404, $this->get('/equipe/' . $f['id'] . '/editar')->status);
    }

    public function testResendInviteOnlyWithoutPassword(): void
    {
        $this->asAdmin();
        $pending = $this->makeUser('secretaria', ['password_hash' => null]);
        $this->post('/equipe/' . $pending['id'] . '/convite');
        $this->assertSame(1, (int) $this->db->one('SELECT COUNT(*) c FROM mail_queue')['c']);
        $set = $this->makeUser('secretaria');
        $this->post('/equipe/' . $set['id'] . '/convite');
        $this->assertSame(1, (int) $this->db->one('SELECT COUNT(*) c FROM mail_queue')['c']);
    }
}
