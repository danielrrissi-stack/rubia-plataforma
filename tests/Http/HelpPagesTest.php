<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Core\App;
use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Help\Manual;
use Tests\DbTestCase;

final class HelpPagesTest extends DbTestCase
{
    /** @var array<string, array> */
    private array $u = [];

    protected function setUp(): void
    {
        parent::setUp();
        $admin = $this->makeUser('medico', ['is_admin' => 1]);
        $this->u = [
            'medico' => $admin,
            'nutricionista' => $this->makeUser('nutricionista'),
            'secretaria' => $this->makeUser('secretaria'),
            'responsavel' => $this->makeUser('responsavel'),
            'paciente' => $this->makeUser('paciente'),
            'crianca' => $this->makeUser('crianca', ['email' => null, 'username' => 'ajuda.' . bin2hex(random_bytes(3))]),
        ];
        $child = $this->makePatient([
            'full_name' => 'Crianca Ajuda', 'sex' => 'F', 'birth_date' => ((int) date('Y') - 8) . '-03-10',
            'responsible_professional_id' => $admin['id'], 'created_by' => $admin['id'],
            'child_user_id' => $this->u['crianca']['id'],
        ]);
        $this->makePatient([
            'full_name' => 'Adulto Ajuda', 'sex' => 'M', 'birth_date' => ((int) date('Y') - 40) . '-06-15',
            'user_id' => $this->u['paciente']['id'],
            'responsible_professional_id' => $admin['id'], 'created_by' => $admin['id'],
        ]);
        $this->db->insert('guardian_links', [
            'patient_id' => $child['id'], 'user_id' => $this->u['responsavel']['id'], 'relationship' => 'mãe', 'created_by' => $admin['id'],
        ]);
        if ($this->db->one("SELECT id FROM consent_terms WHERE version = '1.0'") === null) {
            $this->db->insert('consent_terms', ['version' => '1.0', 'body_html' => '<p>Termo</p>', 'active' => 1]);
        }
        $this->db->run('UPDATE consent_terms SET active = 1 WHERE version = ?', ['1.0']);
    }

    private function acceptConsents(array $user): void
    {
        foreach (Container::consents()->pendingFor($user) as $p) {
            Container::consents()->accept((int) $user['id'], $p['patient_id'], $p['term_version'], new Request('POST', '/consentimento'));
        }
    }

    private function loginAs(array $u): void
    {
        Session::reset();
        $this->db->run('UPDATE users SET totp_enabled = 1 WHERE id = ?', [$u['id']]);
        Container::auth()->completeLogin((int) $u['id'], new Request('POST', '/login'));
    }

    private function get(string $path): Response
    {
        return App::create()->handle(new Request('GET', $path));
    }

    private function adminTitle(): string
    {
        foreach (Manual::sections('medico', true) as $s) {
            if ($s['admin']) {
                return $s['title'];
            }
        }
        $this->markTestSkipped('Ainda nao ha secao de administracao no conteudo.');
    }

    public function test_each_role_opens_own_manual(): void
    {
        $this->acceptConsents($this->u['responsavel']);
        $this->acceptConsents($this->u['paciente']);
        foreach (Manual::ROLES as $role) {
            $this->loginAs($this->u[$role]);
            $res = $this->get('/ajuda');
            $this->assertSame(200, $res->status, $role);
            $this->assertStringContainsString(e(Manual::title($role)), $res->body, $role);
            $this->assertStringContainsString(e(Manual::NOTICE), $res->body, $role);
            $this->assertStringContainsString('manual-' . Manual::slug($role) . '.pdf', $res->body, $role);
            $this->assertStringContainsString(e(Manual::CAPTION_SUFFIX), $res->body, $role);
        }
    }

    public function test_admin_doctor_opens_any_manual_and_sees_admin_sections(): void
    {
        $this->loginAs($this->u['medico']);
        $res = $this->get('/ajuda/responsavel');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString(e('Manual do responsável'), $res->body);
        $this->assertStringContainsString('href="' . url('/ajuda/crianca') . '"', $this->get('/ajuda')->body);
        $this->assertStringContainsString(e($this->adminTitle()), $this->get('/ajuda')->body);
    }

    public function test_non_admin_doctor_is_limited(): void
    {
        $doc = $this->makeUser('medico', ['is_admin' => 0]);
        $this->loginAs($doc);
        $this->assertSame(403, $this->get('/ajuda/responsavel')->status);
        $this->assertSame(200, $this->get('/ajuda/medica')->status);
        $this->assertStringNotContainsString(e($this->adminTitle()), $this->get('/ajuda')->body);
    }

    public function test_other_roles_get_403_on_foreign_manual_and_404_on_unknown(): void
    {
        $this->loginAs($this->u['secretaria']);
        $this->assertSame(403, $this->get('/ajuda/medica')->status);
        $this->assertSame(200, $this->get('/ajuda/secretaria')->status);
        $this->assertSame(404, $this->get('/ajuda/xyz')->status);
    }

    public function test_child_reaches_help(): void
    {
        $this->loginAs($this->u['crianca']);
        $this->assertSame(200, $this->get('/ajuda')->status);
        $this->assertSame(200, $this->get('/ajuda/crianca')->status);
        $this->assertSame(302, $this->get('/ajuda/medica')->status);
    }

    public function test_family_with_pending_consent_is_sent_to_consent(): void
    {
        $this->loginAs($this->u['responsavel']);
        $res = $this->get('/ajuda');
        $this->assertSame(302, $res->status);
        $this->assertStringEndsWith('/consentimento', $res->headers['Location'] ?? '');
    }

    public function test_help_link_in_both_layouts(): void
    {
        $this->loginAs($this->u['medico']);
        $this->assertStringContainsString('href="' . url('/ajuda') . '"', $this->get('/pacientes')->body);
        $this->acceptConsents($this->u['responsavel']);
        $this->loginAs($this->u['responsavel']);
        $res = $this->get('/portal');
        if ($res->status === 302) {
            $res = $this->get($res->headers['Location']);
        }
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('href="' . url('/ajuda') . '"', $res->body);
    }
}
